<?php

namespace App\Jobs;

use App\A2A\A2AState;
use App\A2A\TaskPayloadFactory;
use App\Models\A2AChildTask;
use App\Models\AgentToolCall;
use App\Neuron\RuntimeAgentFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
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
            $prompt = $childTask->request_payload['message'] ?? '';
            $response = $agents
                ->make($childTask->remote_agent_slug)
                ->chat(new UserMessage($prompt))
                ->getMessage()
                ->getContent() ?? '';

            $artifact = $payloads->artifact($response);

            AgentToolCall::query()
                ->whereKey($childTask->tool_call_id)
                ->where('state', 'waiting')
                ->update([
                    'state' => 'completed',
                    'result' => [
                        'remote_task_id' => $childTask->remote_task_id,
                        'artifact' => $artifact,
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
                    'status' => ['state' => A2AState::FAILED],
                ],
            ]);

            ResumeParentAgentJob::dispatch($childTask->agent_run_id);

            report($exception);

            throw $exception;
        }
    }
}
