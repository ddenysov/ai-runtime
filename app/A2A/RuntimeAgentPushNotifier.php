<?php

namespace App\A2A;

use App\Models\A2ATaskPushNotification;
use Illuminate\Support\Facades\Http;
use Throwable;

class RuntimeAgentPushNotifier
{
    public function sendStatusUpdate(array $task): void
    {
        $this->send($task, [
            'statusUpdate' => [
                'taskId' => $task['id'],
                'contextId' => $task['contextId'],
                'status' => $task['status'],
            ],
        ]);
    }

    public function sendArtifactUpdate(array $task, array $artifact): void
    {
        $this->send($task, [
            'artifactUpdate' => [
                'taskId' => $task['id'],
                'contextId' => $task['contextId'],
                'artifact' => $artifact,
                'lastChunk' => true,
            ],
        ]);
    }

    private function send(array $task, array $payload): void
    {
        $config = A2ATaskPushNotification::query()
            ->where('a2a_task_id', $task['id'])
            ->first();

        if ($config === null) {
            return;
        }

        try {
            $request = Http::withHeaders([
                'Content-Type' => 'application/a2a+json',
            ])->acceptJson();
            $auth = $config->authentication ?? [];

            if (($auth['scheme'] ?? null) === 'Bearer' && filled($auth['credentials'] ?? null)) {
                $request = $request->withToken($auth['credentials']);
            }

            if (filled($config->notification_token)) {
                $request = $request->withHeader('X-A2A-Notification-Token', $config->notification_token);
            }

            $response = $request->post($config->url, $payload);

            $config->update([
                'last_status' => (string) $response->status(),
                'last_error' => $response->successful() ? null : $response->body(),
            ]);
        } catch (Throwable $exception) {
            $config->update([
                'last_status' => 'exception',
                'last_error' => $exception->getMessage(),
            ]);

            report($exception);
        }
    }
}
