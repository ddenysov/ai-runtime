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
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use Throwable;

class ProcessA2ATask implements ShouldQueue
{
    use Queueable;

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

            $agent = $agents->make($agentSlug, new RuntimeAgentContext(
                agentSlug: $agentSlug,
                agentRunId: $run->id,
                a2aTaskId: $task['id'],
                resumeToken: $run->workflow_resume_token,
            ));
            $response = $agent->chat(new UserMessage($input))->getMessage()->getContent() ?? '';

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
            $notifier->sendArtifactUpdate($completed, $artifact);
            $notifier->sendStatusUpdate($completed);
            $run->update([
                'state' => 'completed',
                'output' => [
                    'message' => $response,
                    'artifact' => $artifact,
                ],
                'workflow_resume_token' => null,
                'resumable_at' => null,
            ]);
            $this->completeChildTaskIfNeeded($task, $artifact);
        } catch (WorkflowInterrupt $interrupt) {
            $run = $this->resolveRun($task, $task['metadata']['agent_slug'] ?? config('runtime-agents.default'));
            $run->update([
                'state' => 'waiting_for_tool',
                'workflow_resume_token' => $interrupt->getWorkflowId(),
                'conversation_state' => $interrupt->jsonSerialize(),
                'resumable_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $failedMessage = $payloads->agentMessage('Task failed while processing.');
            $failed = $tasks->updateState($task, A2AState::FAILED, $failedMessage);
            $notifier->sendStatusUpdate($failed);

            $this->resolveRun($task, $task['metadata']['agent_slug'] ?? config('runtime-agents.default'))
                ->update([
                    'state' => 'failed',
                    'output' => ['error' => $exception->getMessage()],
                ]);
            $this->failChildTaskIfNeeded($task, $exception->getMessage());

            report($exception);

            throw $exception;
        }
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
                'state' => 'submitted',
                'input' => [
                    'a2a_task_id' => $task['id'],
                ],
            ],
        );
    }

    private function completeChildTaskIfNeeded(array $task, array $artifact): void
    {
        $childTask = A2AChildTask::query()
            ->where('remote_task_id', $task['id'])
            ->whereNotIn('state', [A2AState::COMPLETED, A2AState::FAILED, A2AState::CANCELED])
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

    private function failChildTaskIfNeeded(array $task, string $error): void
    {
        $childTask = A2AChildTask::query()
            ->where('remote_task_id', $task['id'])
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

        ResumeParentAgentJob::dispatch($childTask->agent_run_id);
    }
}
