<?php

namespace App\Jobs;

use App\A2A\A2AState;
use App\A2A\TaskPayloadFactory;
use App\Models\A2AChildTask;
use App\Models\AgentRun;
use App\Models\AgentToolCall;
use App\Neuron\RuntimeAgentFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use NeuronAI\Chat\Messages\UserMessage;
use Throwable;

class ProcessA2AChildTask implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 90;

    public function __construct(
        public int $childTaskId,
    ) {}

    public function handle(RuntimeAgentFactory $agents, TaskPayloadFactory $payloads): void
    {
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
                $this->failFromNestedSubagent($childTask, (string) ($nestedResult['error'] ?? 'Nested subagent failed.'));

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
                'last_notification' => [
                    'kind' => 'artifactUpdate',
                    'taskId' => $childTask->remote_task_id,
                    'contextId' => $childTask->remote_context_id,
                    'artifact' => $artifact,
                ],
            ]);

            ResumeParentAgentJob::dispatch($childTask->agent_run_id);
        } catch (Throwable $exception) {
            AgentToolCall::query()
                ->whereKey($childTask->tool_call_id)
                ->update([
                    'state' => 'failed',
                    'error' => $exception->getMessage(),
                ]);

            $childTask->update([
                'state' => A2AState::FAILED,
                'last_notification' => [
                    'kind' => 'statusUpdate',
                    'taskId' => $childTask->remote_task_id,
                    'contextId' => $childTask->remote_context_id,
                    'status' => ['state' => A2AState::FAILED->value],
                ],
            ]);

            ResumeParentAgentJob::dispatch($childTask->agent_run_id);

            report($exception);

            throw $exception;
        }
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

        $toolCall = AgentToolCall::query()->create([
            'id' => (string) Str::uuid(),
            'agent_run_id' => $run->id,
            'tool_name' => 'remote_a2a_agent',
            'state' => 'waiting',
            'arguments' => [
                'agent_slug' => $agentSlug,
                'message' => $message,
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
            ],
        ]);

        return [
            'run' => $run,
            'tool_call' => $toolCall,
            'child_task' => $childTask,
        ];
    }

    private function failFromNestedSubagent(A2AChildTask $childTask, string $error): void
    {
        AgentToolCall::query()
            ->whereKey($childTask->tool_call_id)
            ->update([
                'state' => 'failed',
                'error' => $error,
            ]);

        $childTask->update([
            'state' => A2AState::FAILED,
            'last_notification' => [
                'kind' => 'statusUpdate',
                'taskId' => $childTask->remote_task_id,
                'contextId' => $childTask->remote_context_id,
                'status' => ['state' => A2AState::FAILED->value],
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
}
