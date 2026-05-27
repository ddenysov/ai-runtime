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
            'kind' => 'statusUpdate',
            'taskId' => $task['id'],
            'contextId' => $task['contextId'],
            'status' => $task['status'],
        ]);
    }

    public function sendArtifactUpdate(array $task, array $artifact): void
    {
        $this->send($task, [
            'kind' => 'artifactUpdate',
            'taskId' => $task['id'],
            'contextId' => $task['contextId'],
            'artifact' => $artifact,
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
            $request = Http::acceptJson();
            $auth = $config->authentication ?? [];

            if (($auth['scheme'] ?? null) === 'Bearer' && filled($auth['credentials'] ?? null)) {
                $request = $request->withToken($auth['credentials']);
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
