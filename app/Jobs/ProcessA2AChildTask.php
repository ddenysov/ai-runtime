<?php

namespace App\Jobs;

use App\A2A\A2AState;
use App\A2A\A2AInvocationGuard;
use App\A2A\Recovery\A2AErrorClassifier;
use App\A2A\Recovery\A2AFailure;
use App\A2A\Recovery\A2AFailureKind;
use App\A2A\Recovery\A2AFallbackService;
use App\A2A\Recovery\A2ARetryPolicy;
use App\A2A\TaskPayloadFactory;
use App\Models\A2AChildTask;
use App\Models\AgentRun;
use App\Models\AgentToolCall;
use App\Neuron\RuntimeAgentFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use NeuronAI\Chat\Messages\UserMessage;
use Throwable;

class ProcessA2AChildTask implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 90;

    public function __construct(
        public int $childTaskId,
    ) {}

    public function handle(
        RuntimeAgentFactory $agents,
        TaskPayloadFactory $payloads,
        A2AErrorClassifier $errors,
        A2ARetryPolicy $retryPolicy,
        A2AFallbackService $fallbacks,
    ): void {
        $childTask = A2AChildTask::query()->find($this->childTaskId);

        if ($childTask === null || A2AState::isTerminal($childTask->state)) {
            return;
        }

        $childTask->update(['state' => A2AState::WORKING]);

        try {
            $requestPayload = $childTask->request_payload ?? [];
            $nestedResult = $this->prepareNestedSubagent($childTask, $requestPayload);

            if ($nestedResult === false) {
                return;
            }

            if (($nestedResult['state'] ?? null) === 'failed') {
                $this->failFromNestedSubagent($childTask, new A2AFailure(
                    kind: A2AFailureKind::UNKNOWN,
                    message: (string) ($nestedResult['error'] ?? 'Nested subagent failed.'),
                ));

                return;
            }

            $prompt = $requestPayload['message'] ?? '';
            $response = $agents
                ->make($childTask->remote_agent_slug)
                ->chat(new UserMessage($prompt))
                ->getMessage()
                ->getContent() ?? '';
            $response = $this->combineResponses($childTask->remote_agent_slug, $response, $nestedResult);

            $artifact = $payloads->artifact($response);

            AgentToolCall::query()
                ->whereKey($childTask->tool_call_id)
                ->where('state', 'waiting')
                ->update([
                    'state' => 'completed',
                    'result' => [
                        'remote_task_id' => $childTask->remote_task_id,
                        'artifact' => $artifact,
                        'nested' => $nestedResult,
                    ],
                ]);

            $childTask->update([
                'state' => A2AState::COMPLETED,
                'last_error_kind' => null,
                'last_error_message' => null,
                'next_attempt_at' => null,
                'failed_at' => null,
                'last_notification' => [
                    'kind' => 'artifactUpdate',
                    'taskId' => $childTask->remote_task_id,
                    'contextId' => $childTask->remote_context_id,
                    'artifact' => $artifact,
                ],
            ]);

            ResumeParentAgentJob::dispatch($childTask->agent_run_id);
        } catch (Throwable $exception) {
            $failure = $errors->classify($exception);

            if ($this->retryChildTask($childTask, $failure, $retryPolicy)) {
                return;
            }

            if ($fallbacks->switchLocalChildTask($childTask, $failure)) {
                Log::info('Local A2A child task switched to fallback subagent.', [
                    'child_task_id' => $childTask->id,
                    'tool_call_id' => $childTask->tool_call_id,
                    'error_kind' => $failure->kind->value,
                ]);
                self::dispatch($childTask->id);

                return;
            }

            AgentToolCall::query()
                ->whereKey($childTask->tool_call_id)
                ->update([
                    'state' => 'failed',
                    'result' => ['error' => $failure->toArray()],
                    'error' => $failure->message,
                    'error_kind' => $failure->kind->value,
                ]);

            $childTask->update([
                'state' => $this->finalStateFor($failure),
                'last_error_kind' => $failure->kind->value,
                'last_error_message' => $failure->message,
                'next_attempt_at' => null,
                'failed_at' => now(),
                'last_notification' => [
                    'kind' => 'statusUpdate',
                    'taskId' => $childTask->remote_task_id,
                    'contextId' => $childTask->remote_context_id,
                    'status' => [
                        'state' => $this->finalStateFor($failure)->value,
                        'message' => $this->finalMessage($failure),
                    ],
                ],
            ]);
            Log::warning('A2A child task reached final failure state.', [
                'child_task_id' => $childTask->id,
                'tool_call_id' => $childTask->tool_call_id,
                'state' => $this->finalStateFor($failure)->value,
                'error_kind' => $failure->kind->value,
                'error' => $failure->message,
            ]);

            ResumeParentAgentJob::dispatch($childTask->agent_run_id);

            report($exception);
        }
    }

    private function retryChildTask(A2AChildTask $childTask, A2AFailure $failure, A2ARetryPolicy $retryPolicy): bool
    {
        $childTask->refresh();

        if ($childTask->state->terminal()) {
            return true;
        }

        $attempt = ((int) $childTask->attempts) + 1;

        if (! $retryPolicy->shouldRetry($failure, $attempt)) {
            return false;
        }

        $delay = $retryPolicy->delaySeconds($failure, $attempt);
        $nextAttemptAt = now()->addSeconds($delay);
        $requestPayload = $childTask->request_payload ?? [];

        $childTask->update([
            'state' => A2AState::WORKING,
            'attempts' => $attempt,
            'last_error_kind' => $failure->kind->value,
            'last_error_message' => $failure->message,
            'next_attempt_at' => $nextAttemptAt,
            'request_payload' => [
                ...$requestPayload,
                'recovery' => [
                    ...$failure->toArray(),
                    'attempt' => $attempt,
                    'next_attempt_at' => $nextAttemptAt->toISOString(),
                ],
            ],
            'last_notification' => [
                'kind' => 'statusUpdate',
                'taskId' => $childTask->remote_task_id,
                'contextId' => $childTask->remote_context_id,
                'status' => [
                    'state' => A2AState::WORKING->value,
                    'message' => "Child task retrying after {$failure->kind->value}; attempt {$attempt}.",
                ],
            ],
        ]);

        $this->release($delay);
        Log::info('A2A child task scheduled for retry.', [
            'child_task_id' => $childTask->id,
            'tool_call_id' => $childTask->tool_call_id,
            'error_kind' => $failure->kind->value,
            'attempt' => $attempt,
            'delay_seconds' => $delay,
            'next_attempt_at' => $nextAttemptAt->toISOString(),
        ]);

        return true;
    }

    private function prepareNestedSubagent(A2AChildTask $childTask, array $requestPayload): array|false|null
    {
        $nestedAgentSlug = $requestPayload['nested_agent_slug'] ?? null;

        if (! is_string($nestedAgentSlug) || $nestedAgentSlug === '') {
            return null;
        }

        $nestedRunId = $requestPayload['nested_run_id'] ?? null;

        if (! is_string($nestedRunId)) {
            $nestedMessage = (string) ($requestPayload['nested_message'] ?? $requestPayload['message'] ?? '');
            $nested = $this->createNestedSubagentTask($childTask, $nestedAgentSlug, $nestedMessage);

            $childTask->update([
                'request_payload' => [
                    ...$requestPayload,
                    'nested_run_id' => $nested['run']->id,
                    'nested_tool_call_id' => $nested['tool_call']->id,
                    'nested_child_task_id' => $nested['child_task']->id,
                ],
            ]);

            ProcessA2AChildTask::dispatch($nested['child_task']->id);

            return false;
        }

        $nestedRun = AgentRun::query()->find($nestedRunId);

        if ($nestedRun === null || $nestedRun->state === 'waiting_for_tool') {
            return false;
        }

        $nestedToolResult = $nestedRun->output['tool_result'] ?? null;

        return [
            'state' => $nestedRun->state,
            'agent_slug' => $nestedAgentSlug,
            'run_id' => $nestedRun->id,
            'tool_call_id' => $requestPayload['nested_tool_call_id'] ?? null,
            'child_task_id' => $requestPayload['nested_child_task_id'] ?? null,
            'remote_task_id' => is_array($nestedToolResult) ? ($nestedToolResult['remote_task_id'] ?? null) : null,
            'artifact' => is_array($nestedToolResult) ? ($nestedToolResult['artifact'] ?? null) : null,
            'error' => $nestedRun->output['tool_error'] ?? null,
        ];
    }

    private function createNestedSubagentTask(A2AChildTask $parentChildTask, string $agentSlug, string $message): array
    {
        $run = AgentRun::query()->create([
            'id' => (string) Str::uuid(),
            'agent_slug' => $parentChildTask->remote_agent_slug,
            'state' => 'waiting_for_tool',
            'input' => [
                'prompt' => $message,
                'parent_child_task_id' => $parentChildTask->id,
            ],
            'resumable_at' => now(),
        ]);
        $invocations = app(A2AInvocationGuard::class);
        $parentInvocation = $parentChildTask->request_payload['invocation'] ?? null;
        $parentInvocation = is_array($parentInvocation)
            ? $invocations->withAgentRun($parentInvocation, $run->id)
            : null;
        $invocation = $invocations->authorizeFromInvocation(
            parentInvocation: $parentInvocation,
            parentTaskId: null,
            parentAgentRunId: $run->id,
            parentAgentSlug: $parentChildTask->remote_agent_slug,
            childAgentSlug: $agentSlug,
        );
        $run->update([
            'input' => [
                ...($run->input ?? []),
                ...($parentInvocation === null ? [] : ['invocation' => $parentInvocation]),
            ],
        ]);

        $toolCall = AgentToolCall::query()->create([
            'id' => (string) Str::uuid(),
            'agent_run_id' => $run->id,
            'tool_name' => 'remote_a2a_agent',
            'state' => 'waiting',
            'arguments' => [
                'agent_slug' => $agentSlug,
                'message' => $message,
                'invocation' => $invocation,
            ],
        ]);

        $childTask = A2AChildTask::query()->create([
            'agent_run_id' => $run->id,
            'tool_call_id' => $toolCall->id,
            'remote_agent_slug' => $agentSlug,
            'remote_task_id' => (string) Str::uuid(),
            'remote_context_id' => (string) Str::uuid(),
            'state' => A2AState::SUBMITTED,
            'request_payload' => [
                'message' => $message,
                'invocation' => $invocation,
            ],
        ]);

        return [
            'run' => $run,
            'tool_call' => $toolCall,
            'child_task' => $childTask,
        ];
    }

    private function failFromNestedSubagent(A2AChildTask $childTask, A2AFailure $failure): void
    {
        AgentToolCall::query()
            ->whereKey($childTask->tool_call_id)
            ->update([
                'state' => 'failed',
                'result' => ['error' => $failure->toArray()],
                'error' => $failure->message,
                'error_kind' => $failure->kind->value,
            ]);

        $childTask->update([
            'state' => $this->finalStateFor($failure),
            'last_error_kind' => $failure->kind->value,
            'last_error_message' => $failure->message,
            'next_attempt_at' => null,
            'failed_at' => now(),
            'last_notification' => [
                'kind' => 'statusUpdate',
                'taskId' => $childTask->remote_task_id,
                'contextId' => $childTask->remote_context_id,
                'status' => [
                    'state' => $this->finalStateFor($failure)->value,
                    'message' => $this->finalMessage($failure),
                ],
            ],
        ]);

        ResumeParentAgentJob::dispatch($childTask->agent_run_id);
    }

    private function combineResponses(string $agentSlug, string $response, ?array $nestedResult): string
    {
        if ($nestedResult === null) {
            return $response;
        }

        $nestedAgentSlug = (string) ($nestedResult['agent_slug'] ?? 'nested_subagent');
        $nestedResponse = $this->artifactText($nestedResult['artifact'] ?? null);

        return trim("Subagent {$agentSlug} response:\n{$response}\n\nSubagent {$nestedAgentSlug} response:\n{$nestedResponse}");
    }

    private function artifactText(mixed $artifact): string
    {
        if (! is_array($artifact)) {
            return '';
        }

        $chunks = [];

        foreach (($artifact['parts'] ?? []) as $part) {
            if (isset($part['text'])) {
                $chunks[] = $part['text'];
            } elseif (isset($part['data'])) {
                $chunks[] = json_encode($part['data'], JSON_THROW_ON_ERROR);
            }
        }

        return trim(implode("\n", $chunks));
    }

    private function finalStateFor(A2AFailure $failure): A2AState
    {
        return match ($failure->kind) {
            A2AFailureKind::CONTENT_POLICY,
            A2AFailureKind::INVALID_REQUEST,
            A2AFailureKind::INVOCATION_LIMIT,
            A2AFailureKind::AUTH => A2AState::REJECTED,
            default => A2AState::FAILED,
        };
    }

    private function finalMessage(A2AFailure $failure): string
    {
        return match ($this->finalStateFor($failure)) {
            A2AState::REJECTED => "Child task rejected: {$failure->kind->value}.",
            default => "Child task failed after recovery attempts: {$failure->kind->value}.",
        };
    }
}
