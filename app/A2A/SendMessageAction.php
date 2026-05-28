<?php

namespace App\A2A;

use App\Jobs\ProcessA2ATask;
use App\Models\AgentRun;
use Illuminate\Support\Str;

class SendMessageAction
{
    public function __construct(
        private readonly RuntimeAgentTaskRepository $tasks,
        private readonly RuntimeAgentPushNotificationRepository $pushNotifications,
        private readonly TaskPayloadFactory $payloads,
    ) {}

    public function handle(string $agentSlug, array $message, ?array $configuration = null, array $metadata = []): array
    {
        $runId = $metadata['agent_run_id'] ?? (string) Str::uuid();
        $metadata['agent_run_id'] = $runId;

        $task = $this->payloads->task($agentSlug, $message, $metadata);

        AgentRun::query()->firstOrCreate(
            ['id' => $runId],
            [
                'agent_slug' => $agentSlug,
                'state' => 'submitted',
                'input' => [
                    'a2a_task_id' => $task['id'],
                    'message' => $message,
                    'parent_agent_run_id' => $metadata['parent_agent_run_id'] ?? null,
                    'parent_tool_call_id' => $metadata['parent_tool_call_id'] ?? null,
                ],
            ],
        );

        $this->tasks->save($task);
        $this->pushNotifications->saveFromConfiguration($task['id'], $configuration);

        ProcessA2ATask::dispatch($task['id']);

        return $task;
    }
}
