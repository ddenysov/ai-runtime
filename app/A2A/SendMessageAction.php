<?php

namespace App\A2A;

use App\Jobs\ProcessA2ATask;

class SendMessageAction
{
    public function __construct(
        private readonly RuntimeAgentTaskRepository $tasks,
        private readonly RuntimeAgentPushNotificationRepository $pushNotifications,
        private readonly TaskPayloadFactory $payloads,
    ) {}

    public function handle(string $agentSlug, array $message, ?array $configuration = null, array $metadata = []): array
    {
        $task = $this->payloads->task($agentSlug, $message, $metadata);

        $this->tasks->save($task);
        $this->pushNotifications->saveFromConfiguration($task['id'], $configuration);

        ProcessA2ATask::dispatch($task['id']);

        return $task;
    }
}
