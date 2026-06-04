<?php

namespace App\Jobs;

use App\A2A\A2AState;
use App\A2A\Recovery\A2AErrorClassifier;
use App\A2A\Recovery\A2AFailure;
use App\A2A\Recovery\A2AFailureKind;
use App\A2A\Recovery\A2ARetryPolicy;
use App\A2A\RuntimeAgentPushNotifier;
use App\A2A\RuntimeAgentTaskRepository;
use App\A2A\TaskPayloadFactory;
use App\Channels\Services\TelegramOutboundMessenger;
use App\Models\A2AChildTask;
use App\Models\AgentChatMessage;
use App\Models\AgentRun;
use App\Models\AgentToolCall;
use App\Neuron\RuntimeAgentContext;
use App\Neuron\RuntimeAgentFactory;
use App\Neuron\Tools\RemoteA2AToolResult;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use Throwable;

class ResumeParentAgentJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public function __construct(
        public string $agentRunId,
    ) {}

    public int $tries = 3;

    public int $timeout = 120;

    public function handle(
        RuntimeAgentFactory $agents,
        RuntimeAgentTaskRepository $tasks,
        RuntimeAgentPushNotifier $notifier,
        TaskPayloadFactory $payloads,
        A2AErrorClassifier $errors,
        A2ARetryPolicy $retryPolicy,
        TelegramOutboundMessenger $telegram,
    ): void {
        $run = AgentRun::query()->find($this->agentRunId);

        if ($run === null || $run->state !== 'waiting_for_tool' || $run->workflow_resume_token === null) {
            return;
        }

        $toolCall = AgentToolCall::query()
            ->where('agent_run_id', $run->id)
            ->whereIn('state', ['completed', 'failed'])
            ->whereNull('applied_at')
            ->first();

        if ($toolCall === null) {
            return;
        }

        try {
            $agent = $agents->make($run->agent_slug, new RuntimeAgentContext(
                agentSlug: $run->agent_slug,
                agentRunId: $run->id,
                a2aTaskId: $run->input['a2a_task_id'] ?? null,
                resumeToken: $run->workflow_resume_token,
                conversationId: $run->input['context_id'] ?? null,
            ));

            $message = $agent
                ->chat([], new RemoteA2AToolResult(
                    toolCallId: $toolCall->id,
                    result: $toolCall->result ?? [],
                    error: $toolCall->error,
                ))
                ->getMessage()
                ->getContent() ?? '';

            $artifact = $payloads->artifact($message);
            $completed = $this->completeA2ATask($run, $tasks, $notifier, $payloads, $message, $artifact);

            if ($completed !== null) {
                $telegram->deliverForTask($completed, $message);
            }

            $run->update([
                'state' => 'completed',
                'last_error_kind' => null,
                'last_error_message' => null,
                'next_attempt_at' => null,
                'failed_at' => null,
                'output' => [
                    'message' => $message,
                    'tool_call_id' => $toolCall->id,
                    'tool_state' => $toolCall->state,
                    'tool_result' => $toolCall->result,
                    'tool_error' => $toolCall->error,
                    'artifact' => $artifact,
                ],
                'workflow_resume_token' => null,
                'resumable_at' => null,
            ]);
            $toolCall->update(['applied_at' => now()]);
            $this->completeChildTaskIfNeeded($run, $artifact);
        } catch (WorkflowInterrupt $interrupt) {
            $run->update([
                'state' => 'waiting_for_tool',
                'workflow_resume_token' => $interrupt->getWorkflowId(),
                'conversation_state' => $interrupt->jsonSerialize(),
                'resumable_at' => now(),
            ]);
            $toolCall->update(['applied_at' => now()]);
            $this->deliverLatestTelegramAssistantMessage($run, $tasks, $telegram);
        } catch (Throwable $exception) {
            $failure = $errors->classify($exception);

            if ($this->retryResume($run, $failure, $retryPolicy)) {
                return;
            }

            $failed = $this->failA2ATask($run, $tasks, $notifier, $payloads, $failure);

            if ($failed !== null) {
                $telegram->deliverFailureForTask($failed, $this->finalMessage($failure));
            }

            $run->update([
                'state' => 'failed',
                'last_error_kind' => $failure->kind->value,
                'last_error_message' => $failure->message,
                'next_attempt_at' => null,
                'failed_at' => now(),
                'output' => [
                    'tool_call_id' => $toolCall->id,
                    'tool_state' => $toolCall->state,
                    'tool_result' => $toolCall->result,
                    'tool_error' => $toolCall->error,
                    'tool_error_kind' => $toolCall->error_kind,
                    'error' => $failure->message,
                    'error_kind' => $failure->kind->value,
                ],
            ]);
            $toolCall->update(['applied_at' => now()]);
            $this->failChildTaskIfNeeded($run, $failure);
            Log::warning('A2A parent resume reached final failure state.', [
                'agent_run_id' => $run->id,
                'tool_call_id' => $toolCall->id,
                'error_kind' => $failure->kind->value,
                'error' => $failure->message,
            ]);

            report($exception);
        }
    }

    private function retryResume(AgentRun $run, A2AFailure $failure, A2ARetryPolicy $retryPolicy): bool
    {
        $run->refresh();

        if ($run->state !== 'waiting_for_tool') {
            return true;
        }

        $attempt = ((int) $run->attempts) + 1;

        if (! $retryPolicy->shouldRetry($failure, $attempt)) {
            return false;
        }

        $delay = $retryPolicy->delaySeconds($failure, $attempt);
        $nextAttemptAt = now()->addSeconds($delay);

        $run->update([
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
        Log::info('A2A parent resume scheduled for retry.', [
            'agent_run_id' => $run->id,
            'error_kind' => $failure->kind->value,
            'attempt' => $attempt,
            'delay_seconds' => $delay,
            'next_attempt_at' => $nextAttemptAt->toISOString(),
        ]);

        return true;
    }

    private function completeA2ATask(
        AgentRun $run,
        RuntimeAgentTaskRepository $tasks,
        RuntimeAgentPushNotifier $notifier,
        TaskPayloadFactory $payloads,
        string $message,
        array $artifact,
    ): ?array {
        $taskId = $run->input['a2a_task_id'] ?? null;

        if (! is_string($taskId) || ($task = $tasks->find($taskId)) === null) {
            return null;
        }

        $agentMessage = $payloads->agentMessage($message);
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
        $notifier->sendArtifactUpdate($completed, $artifact);
        $notifier->sendStatusUpdate($completed);

        return $completed;
    }

    private function failA2ATask(
        AgentRun $run,
        RuntimeAgentTaskRepository $tasks,
        RuntimeAgentPushNotifier $notifier,
        TaskPayloadFactory $payloads,
        A2AFailure $failure,
    ): ?array {
        $taskId = $run->input['a2a_task_id'] ?? null;

        if (! is_string($taskId) || ($task = $tasks->find($taskId)) === null) {
            return null;
        }

        $failed = $tasks->updateState($task, $this->finalStateFor($failure), $payloads->agentMessage($this->finalMessage($failure)));
        $notifier->sendStatusUpdate($failed);

        return $failed;
    }

    private function deliverLatestTelegramAssistantMessage(
        AgentRun $run,
        RuntimeAgentTaskRepository $tasks,
        TelegramOutboundMessenger $telegram,
    ): void {
        $taskId = $run->input['a2a_task_id'] ?? null;

        if (! is_string($taskId) || ($task = $tasks->find($taskId)) === null) {
            return;
        }

        if (($task['metadata']['delivery_channel']['type'] ?? null) !== 'telegram') {
            return;
        }

        $contextId = $run->input['context_id'] ?? $run->id;
        $message = AgentChatMessage::query()
            ->where('thread_id', "{$run->agent_slug}:{$contextId}")
            ->where('role', 'assistant')
            ->latest('id')
            ->first();

        if (! $message instanceof AgentChatMessage) {
            return;
        }

        $text = $this->messageText($message->content);

        if ($text === null || $text === '') {
            return;
        }

        $deliveredMessageIds = $run->output['telegram_intermediate_message_ids'] ?? [];
        $deliveredMessageIds = is_array($deliveredMessageIds) ? $deliveredMessageIds : [];

        if (in_array($message->id, $deliveredMessageIds, true)) {
            return;
        }

        $telegram->deliverForTask($task, $text);
        $run->update([
            'output' => [
                ...($run->output ?? []),
                'telegram_intermediate_message_ids' => [
                    ...$deliveredMessageIds,
                    $message->id,
                ],
            ],
        ]);
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

    private function completeChildTaskIfNeeded(AgentRun $run, array $artifact): void
    {
        $taskId = $run->input['a2a_task_id'] ?? null;

        if (! is_string($taskId)) {
            return;
        }

        $childTask = A2AChildTask::query()
            ->where('remote_task_id', $taskId)
            ->whereNotIn('state', [A2AState::COMPLETED, A2AState::FAILED, A2AState::CANCELED, A2AState::REJECTED])
            ->first();

        if ($childTask === null) {
            return;
        }

        $result = [
            'remote_task_id' => $taskId,
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

        self::dispatch($childTask->agent_run_id);
    }

    private function failChildTaskIfNeeded(AgentRun $run, A2AFailure $failure): void
    {
        $taskId = $run->input['a2a_task_id'] ?? null;

        if (! is_string($taskId)) {
            return;
        }

        $childTask = A2AChildTask::query()
            ->where('remote_task_id', $taskId)
            ->whereNotIn('state', [A2AState::COMPLETED, A2AState::FAILED, A2AState::CANCELED, A2AState::REJECTED])
            ->first();

        if ($childTask === null) {
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
                    'message' => $this->finalMessage($failure),
                ],
            ],
        ]);

        self::dispatch($childTask->agent_run_id);
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
            A2AState::REJECTED => "Task rejected while resuming parent: {$failure->kind->value}.",
            default => "Task failed while resuming parent after recovery attempts: {$failure->kind->value}.",
        };
    }
}
