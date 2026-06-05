<?php

namespace App\Jobs;

use App\A2A\A2AState;
use App\A2A\Recovery\A2AErrorClassifier;
use App\A2A\Recovery\A2AFailure;
use App\A2A\Recovery\A2AFailureKind;
use App\A2A\Recovery\A2AFallbackService;
use App\A2A\Recovery\A2ARetryPolicy;
use App\A2A\RuntimeAgentPushNotifier;
use App\A2A\RuntimeAgentTaskRepository;
use App\A2A\TaskPayloadFactory;
use App\Channels\Services\TelegramOutboundMessenger;
use App\Models\A2AChildTask;
use App\Models\A2ATask;
use App\Models\Agent;
use App\Models\AgentChatMessage;
use App\Models\AgentRun;
use App\Models\AgentToolCall;
use App\Neuron\RuntimeAgentContext;
use App\Neuron\RuntimeAgentFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use RuntimeException;
use Throwable;

class ProcessA2ATask implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 90;

    public function __construct(
        public string $taskId,
    ) {}

    public function handle(
        RuntimeAgentTaskRepository $tasks,
        RuntimeAgentPushNotifier $notifier,
        RuntimeAgentFactory $agents,
        TaskPayloadFactory $payloads,
        A2AErrorClassifier $errors,
        A2ARetryPolicy $retryPolicy,
        TelegramOutboundMessenger $telegram,
    ): void {
        $task = $tasks->find($this->taskId);

        if ($task === null || A2AState::isTerminal($task['status']['state'])) {
            return;
        }

        $task = $tasks->updateState($task, A2AState::WORKING);
        $notifier->sendStatusUpdate($task);
        $this->markChildTaskWorkingIfNeeded($task);

        try {
            $input = $this->extractText($task['history'] ?? []);
            $agentSlug = $task['metadata']['agent_slug'] ?? config('runtime-agents.default');
            $run = $this->resolveRun($task, $agentSlug);
            $run->update(['state' => 'working']);
            $this->injectSmokeFailureIfRequested($task, $agentSlug, $tasks);

            $agent = $agents->make($agentSlug, new RuntimeAgentContext(
                agentSlug: $agentSlug,
                agentRunId: $run->id,
                a2aTaskId: $task['id'],
                resumeToken: $run->workflow_resume_token,
                conversationId: $task['contextId'] ?? null,
            ));
            $messages = $this->initialMessages($run, $input);
            $response = $agent->chat(...$messages)->getMessage()->getContent() ?? '';

            $agentMessage = $payloads->agentMessage($response);
            $artifact = $payloads->artifact($response);

            $completed = [
                ...$task,
                'status' => [
                    'state' => A2AState::COMPLETED->value,
                    'message' => $agentMessage,
                ],
                'history' => [
                    ...($task['history'] ?? []),
                    $agentMessage,
                ],
                'artifacts' => [
                    ...($task['artifacts'] ?? []),
                    $artifact,
                ],
            ];

            $tasks->save($completed);
            A2ATask::query()
                ->whereKey($task['id'])
                ->update([
                    'last_error_kind' => null,
                    'last_error_message' => null,
                    'next_attempt_at' => null,
                    'failed_at' => null,
                ]);
            $notifier->sendArtifactUpdate($completed, $artifact);
            $notifier->sendStatusUpdate($completed);
            if (! $this->deliverPendingTelegramAssistantMessages($run, $completed, $telegram)) {
                $telegram->deliverForTask($completed, $response);
            }
            $run->refresh();
            $run->update([
                'state' => 'completed',
                'last_error_kind' => null,
                'last_error_message' => null,
                'next_attempt_at' => null,
                'failed_at' => null,
                'output' => [
                    ...($run->output ?? []),
                    'message' => $response,
                    'artifact' => $artifact,
                ],
                'workflow_resume_token' => null,
                'resumable_at' => null,
            ]);
            $this->completeChildTaskIfNeeded($task, $artifact);
            ProcessAgentStateProcessors::dispatch($run->id, $input, $response);
        } catch (WorkflowInterrupt $interrupt) {
            $run = $this->resolveRun($task, $task['metadata']['agent_slug'] ?? config('runtime-agents.default'));
            $run->update([
                'state' => 'waiting_for_tool',
                'workflow_resume_token' => $interrupt->getWorkflowId(),
                'conversation_state' => $interrupt->jsonSerialize(),
                'resumable_at' => now(),
            ]);
            $this->deliverPendingTelegramAssistantMessages($run, $task, $telegram);
        } catch (Throwable $exception) {
            $failure = $errors->classify($exception);

            if ($this->retryTask($task, $failure, $retryPolicy, $tasks, $notifier, $payloads)) {
                return;
            }

            $finalState = $this->finalStateFor($failure);
            $failedMessage = $payloads->agentMessage($this->finalMessage($failure, 'processing'));
            $latestTask = $tasks->find($this->taskId) ?? $task;

            if (A2AState::isTerminal($latestTask['status']['state'] ?? A2AState::SUBMITTED->value)) {
                return;
            }

            $failed = $tasks->updateState($latestTask, $finalState, $failedMessage);
            $notifier->sendStatusUpdate($failed);
            $telegram->deliverFailureForTask($failed, $this->finalMessage($failure, 'processing'));
            A2ATask::query()
                ->whereKey($this->taskId)
                ->update([
                    'last_error_kind' => $failure->kind->value,
                    'last_error_message' => $failure->message,
                    'next_attempt_at' => null,
                    'failed_at' => now(),
                ]);
            Log::warning('A2A task reached final failure state.', [
                'task_id' => $this->taskId,
                'state' => $finalState->value,
                'error_kind' => $failure->kind->value,
                'error' => $failure->message,
            ]);

            $this->resolveRun($latestTask, $latestTask['metadata']['agent_slug'] ?? config('runtime-agents.default'))
                ->update([
                    'state' => 'failed',
                    'last_error_kind' => $failure->kind->value,
                    'last_error_message' => $failure->message,
                    'next_attempt_at' => null,
                    'failed_at' => now(),
                    'output' => [
                        'error' => $failure->message,
                        'error_kind' => $failure->kind->value,
                    ],
                ]);
            $this->failChildTaskIfNeeded($latestTask, $failure);

            report($exception);
        }
    }

    private function injectSmokeFailureIfRequested(array &$task, string $agentSlug, RuntimeAgentTaskRepository $tasks): void
    {
        $target = $task['metadata']['smoke_fail_once_agent_slug'] ?? null;

        if ($target !== $agentSlug) {
            return;
        }

        $injected = $task['metadata']['smoke_fail_once_injected'] ?? [];
        $injected = is_array($injected) ? $injected : [];

        if (array_key_exists($agentSlug, $injected)) {
            return;
        }

        $task['metadata']['smoke_fail_once_injected'] = [
            ...$injected,
            $agentSlug => now()->toISOString(),
        ];
        $tasks->save($task);

        throw new RuntimeException("Smoke injected temporary network failure for agent [{$agentSlug}].");
    }

    private function retryTask(
        array $task,
        A2AFailure $failure,
        A2ARetryPolicy $retryPolicy,
        RuntimeAgentTaskRepository $tasks,
        RuntimeAgentPushNotifier $notifier,
        TaskPayloadFactory $payloads,
    ): bool {
        $record = A2ATask::query()->find($task['id']);

        if ($record === null) {
            return false;
        }

        if ($record->state instanceof A2AState && $record->state->terminal()) {
            return true;
        }

        $attempt = ((int) $record->attempts) + 1;

        if (! $retryPolicy->shouldRetry($failure, $attempt)) {
            return false;
        }

        $delay = $retryPolicy->delaySeconds($failure, $attempt);
        $nextAttemptAt = now()->addSeconds($delay);
        $retryingTask = $tasks->updateState(
            $record->payload ?? $task,
            A2AState::WORKING,
            $payloads->agentMessage("Task retrying after {$failure->kind->value}; attempt {$attempt}."),
        );
        $notifier->sendStatusUpdate($retryingTask);

        $record->refresh();
        $record->update([
            'attempts' => $attempt,
            'last_error_kind' => $failure->kind->value,
            'last_error_message' => $failure->message,
            'next_attempt_at' => $nextAttemptAt,
        ]);

        $this->resolveRun($retryingTask, $retryingTask['metadata']['agent_slug'] ?? config('runtime-agents.default'))
            ->update([
                'state' => 'working',
                'attempts' => $attempt,
                'last_error_kind' => $failure->kind->value,
                'last_error_message' => $failure->message,
                'next_attempt_at' => $nextAttemptAt,
                'output' => [
                    'recovery' => [
                        ...$failure->toArray(),
                        'attempt' => $attempt,
                        'next_attempt_at' => $nextAttemptAt->toISOString(),
                    ],
                ],
            ]);

        $this->release($delay);
        Log::info('A2A task scheduled for retry.', [
            'task_id' => $task['id'],
            'agent_run_id' => $retryingTask['metadata']['agent_run_id'] ?? null,
            'error_kind' => $failure->kind->value,
            'attempt' => $attempt,
            'delay_seconds' => $delay,
            'next_attempt_at' => $nextAttemptAt->toISOString(),
        ]);

        return true;
    }

    private function extractText(array $messages): string
    {
        $chunks = [];

        foreach ($messages as $message) {
            foreach (($message['parts'] ?? []) as $part) {
                if (isset($part['text'])) {
                    $chunks[] = $part['text'];
                } elseif (isset($part['data'])) {
                    $chunks[] = json_encode($part['data'], JSON_THROW_ON_ERROR);
                }
            }
        }

        return trim(implode("\n", $chunks));
    }

    /**
     * If a provider failed after Neuron persisted the user message, retry from
     * stored history. If the failure happened before chat started, send it now.
     *
     * @return UserMessage[]
     */
    private function initialMessages(AgentRun $run, string $input): array
    {
        $contextId = $run->input['context_id'] ?? $run->id;
        $latestPersistedRole = AgentChatMessage::query()
            ->where('thread_id', "{$run->agent_slug}:{$contextId}")
            ->latest('id')
            ->value('role');

        return $latestPersistedRole === 'user' ? [] : [new UserMessage($input)];
    }

    private function deliverPendingTelegramAssistantMessages(
        AgentRun $run,
        array $task,
        TelegramOutboundMessenger $telegram,
    ): bool {
        if (($task['metadata']['delivery_channel']['type'] ?? null) !== 'telegram') {
            return false;
        }

        $contextId = $run->input['context_id'] ?? $run->id;
        $threadId = "{$run->agent_slug}:{$contextId}";
        $latestHumanUserMessageId = AgentChatMessage::query()
            ->where('thread_id', $threadId)
            ->where('role', 'user')
            ->where(function ($query): void {
                $query
                    ->whereNull('meta->type')
                    ->orWhere('meta->type', '!=', 'tool_call_result');
            })
            ->latest('id')
            ->value('id') ?? 0;

        $deliveredMessageIds = $run->output['telegram_intermediate_message_ids'] ?? [];
        $deliveredMessageIds = is_array($deliveredMessageIds)
            ? array_values(array_unique(array_filter(
                array_map(fn (mixed $id): ?int => is_numeric($id) ? (int) $id : null, $deliveredMessageIds),
                fn (?int $id): bool => $id !== null,
            )))
            : [];

        $query = AgentChatMessage::query()
            ->where('thread_id', $threadId)
            ->where('role', 'assistant')
            ->where('id', '>', (int) $latestHumanUserMessageId)
            ->oldest('id');

        $messages = $query->get();

        if ($messages->isEmpty()) {
            return false;
        }

        $newDeliveredMessageIds = $deliveredMessageIds;
        $hasDeliverableMessages = false;

        foreach ($messages as $message) {
            $text = $this->messageText($message->content);

            if ($text === null || $text === '') {
                continue;
            }

            $hasDeliverableMessages = true;

            if (in_array((int) $message->id, $deliveredMessageIds, true)) {
                continue;
            }

            $telegram->deliverForTask($task, $text);
            $newDeliveredMessageIds[] = $message->id;
        }

        if (count($newDeliveredMessageIds) === count($deliveredMessageIds)) {
            return $hasDeliverableMessages;
        }

        $run->update([
            'output' => [
                ...($run->output ?? []),
                'telegram_intermediate_message_ids' => array_values(array_unique($newDeliveredMessageIds)),
            ],
        ]);

        return $hasDeliverableMessages;
    }

    private function messageText(mixed $message): ?string
    {
        if ($message === null) {
            return null;
        }

        if (is_string($message) || is_numeric($message) || is_bool($message)) {
            return trim((string) $message);
        }

        if (! is_array($message)) {
            return null;
        }

        if (isset($message['text'])) {
            return $this->messageText($message['text']);
        }

        if (isset($message['content'])) {
            return $this->messageText($message['content']);
        }

        if (array_is_list($message)) {
            return collect($message)
                ->map(fn (mixed $part): ?string => $this->messageText($part))
                ->filter()
                ->implode("\n") ?: null;
        }

        return null;
    }

    private function resolveRun(array $task, string $agentSlug): AgentRun
    {
        $runId = $task['metadata']['agent_run_id'] ?? null;

        if (! is_string($runId)) {
            $runId = (string) Str::uuid();
            $task['metadata']['agent_run_id'] = $runId;
        }

        return AgentRun::query()->firstOrCreate(
            ['id' => $runId],
            [
                'agent_slug' => $agentSlug,
                'agent_version_id' => $this->currentAgentVersionId($agentSlug),
                'state' => 'submitted',
                'input' => [
                    'a2a_task_id' => $task['id'],
                ],
            ],
        );
    }

    private function currentAgentVersionId(string $agentSlug): ?int
    {
        $agent = Agent::query()
            ->where('slug', $agentSlug)
            ->first();

        return $agent?->versions()->latest('version')->value('id');
    }

    private function completeChildTaskIfNeeded(array $task, array $artifact): void
    {
        $childTask = A2AChildTask::query()
            ->where('remote_task_id', $task['id'])
            ->whereNotIn('state', [A2AState::COMPLETED, A2AState::FAILED, A2AState::CANCELED, A2AState::REJECTED])
            ->first();

        if ($childTask === null) {
            return;
        }

        $result = [
            'remote_task_id' => $task['id'],
            'artifact' => $artifact,
        ];

        AgentToolCall::query()
            ->whereKey($childTask->tool_call_id)
            ->where('state', 'waiting')
            ->update([
                'state' => 'completed',
                'result' => $result,
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
    }

    private function markChildTaskWorkingIfNeeded(array $task): void
    {
        A2AChildTask::query()
            ->where('remote_task_id', $task['id'])
            ->where('state', A2AState::SUBMITTED)
            ->update([
                'state' => A2AState::WORKING,
                'last_notification' => [
                    'kind' => 'statusUpdate',
                    'taskId' => $task['id'],
                    'contextId' => $task['contextId'] ?? null,
                    'status' => ['state' => A2AState::WORKING->value],
                ],
            ]);
    }

    private function failChildTaskIfNeeded(array $task, A2AFailure $failure): void
    {
        $childTask = A2AChildTask::query()
            ->where('remote_task_id', $task['id'])
            ->whereNotIn('state', [A2AState::COMPLETED, A2AState::FAILED, A2AState::CANCELED, A2AState::REJECTED])
            ->first();

        if ($childTask === null) {
            return;
        }

        if (app(A2AFallbackService::class)->switchRemoteChildTask($childTask, $failure)) {
            Log::info('A2A child task switched to fallback subagent.', [
                'task_id' => $task['id'],
                'child_task_id' => $childTask->id,
                'tool_call_id' => $childTask->tool_call_id,
                'error_kind' => $failure->kind->value,
            ]);

            return;
        }

        AgentToolCall::query()
            ->whereKey($childTask->tool_call_id)
            ->where('state', 'waiting')
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
                    'message' => $this->finalMessage($failure, 'subagent processing'),
                ],
            ],
        ]);

        ResumeParentAgentJob::dispatch($childTask->agent_run_id);
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

    private function finalMessage(A2AFailure $failure, string $phase): string
    {
        return match ($this->finalStateFor($failure)) {
            A2AState::REJECTED => "Task rejected during {$phase}: {$failure->kind->value}.",
            default => "Task failed during {$phase} after recovery attempts: {$failure->kind->value}.",
        };
    }
}
