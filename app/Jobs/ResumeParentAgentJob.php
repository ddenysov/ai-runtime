<?php

namespace App\Jobs;

use App\A2A\A2AState;
use App\A2A\RuntimeAgentPushNotifier;
use App\A2A\RuntimeAgentTaskRepository;
use App\A2A\TaskPayloadFactory;
use App\Models\A2AChildTask;
use App\Models\AgentRun;
use App\Models\AgentToolCall;
use App\Neuron\RuntimeAgentContext;
use App\Neuron\RuntimeAgentFactory;
use App\Neuron\Tools\RemoteA2AToolResult;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use Throwable;

class ResumeParentAgentJob implements ShouldQueue
{
    use Queueable;

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
            $this->completeA2ATask($run, $tasks, $notifier, $payloads, $message, $artifact);

            $run->update([
                'state' => 'completed',
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
        } catch (Throwable $exception) {
            $this->failA2ATask($run, $tasks, $notifier, $payloads);
            $run->update([
                'state' => 'failed',
                'output' => [
                    'tool_call_id' => $toolCall->id,
                    'tool_state' => $toolCall->state,
                    'tool_result' => $toolCall->result,
                    'tool_error' => $toolCall->error,
                    'error' => $exception->getMessage(),
                ],
            ]);
            $toolCall->update(['applied_at' => now()]);
            $this->failChildTaskIfNeeded($run, $exception->getMessage());

            report($exception);

            throw $exception;
        }
    }

    private function completeA2ATask(
        AgentRun $run,
        RuntimeAgentTaskRepository $tasks,
        RuntimeAgentPushNotifier $notifier,
        TaskPayloadFactory $payloads,
        string $message,
        array $artifact,
    ): void {
        $taskId = $run->input['a2a_task_id'] ?? null;

        if (! is_string($taskId) || ($task = $tasks->find($taskId)) === null) {
            return;
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
    }

    private function failA2ATask(
        AgentRun $run,
        RuntimeAgentTaskRepository $tasks,
        RuntimeAgentPushNotifier $notifier,
        TaskPayloadFactory $payloads,
    ): void {
        $taskId = $run->input['a2a_task_id'] ?? null;

        if (! is_string($taskId) || ($task = $tasks->find($taskId)) === null) {
            return;
        }

        $failed = $tasks->updateState($task, A2AState::FAILED, $payloads->agentMessage('Task failed while resuming.'));
        $notifier->sendStatusUpdate($failed);
    }

    private function completeChildTaskIfNeeded(AgentRun $run, array $artifact): void
    {
        $taskId = $run->input['a2a_task_id'] ?? null;

        if (! is_string($taskId)) {
            return;
        }

        $childTask = A2AChildTask::query()
            ->where('remote_task_id', $taskId)
            ->whereNotIn('state', [A2AState::COMPLETED, A2AState::FAILED, A2AState::CANCELED])
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
            'last_notification' => [
                'kind' => 'artifactUpdate',
                'taskId' => $childTask->remote_task_id,
                'contextId' => $childTask->remote_context_id,
                'artifact' => $artifact,
            ],
        ]);

        self::dispatch($childTask->agent_run_id);
    }

    private function failChildTaskIfNeeded(AgentRun $run, string $error): void
    {
        $taskId = $run->input['a2a_task_id'] ?? null;

        if (! is_string($taskId)) {
            return;
        }

        $childTask = A2AChildTask::query()
            ->where('remote_task_id', $taskId)
            ->whereNotIn('state', [A2AState::COMPLETED, A2AState::FAILED, A2AState::CANCELED])
            ->first();

        if ($childTask === null) {
            return;
        }

        AgentToolCall::query()
            ->whereKey($childTask->tool_call_id)
            ->where('state', 'waiting')
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

        self::dispatch($childTask->agent_run_id);
    }
}
