<?php

namespace App\A2A;

use App\Models\A2ATaskPushNotification;

class RuntimeAgentPushNotificationRepository
{
    public function saveFromConfiguration(string $taskId, ?array $configuration): void
    {
        $pushConfig = $configuration['taskPushNotificationConfig']['pushNotificationConfig']
            ?? $configuration['pushNotificationConfig']
            ?? null;

        if (! is_array($pushConfig) || blank($pushConfig['url'] ?? null)) {
            return;
        }

        A2ATaskPushNotification::query()->updateOrCreate(
            ['a2a_task_id' => $taskId],
            [
                'url' => $pushConfig['url'],
                'authentication' => $pushConfig['authentication'] ?? null,
            ],
        );
    }
}
