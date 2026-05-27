<?php

namespace App\A2A;

use Illuminate\Support\Str;

class TaskPayloadFactory
{
    public function userMessage(string $text, ?string $messageId = null): array
    {
        return [
            'messageId' => $messageId ?? (string) Str::uuid(),
            'role' => 'ROLE_USER',
            'parts' => [
                [
                    'text' => $text,
                    'mediaType' => 'text/plain',
                ],
            ],
        ];
    }

    public function agentMessage(string $text): array
    {
        return [
            'messageId' => (string) Str::uuid(),
            'role' => 'ROLE_AGENT',
            'parts' => [
                [
                    'text' => $text,
                    'mediaType' => 'text/plain',
                ],
            ],
        ];
    }

    public function artifact(string $text): array
    {
        return [
            'artifactId' => (string) Str::uuid(),
            'name' => 'response',
            'parts' => [
                [
                    'text' => $text,
                    'mediaType' => 'text/plain',
                ],
            ],
        ];
    }

    public function task(string $agentSlug, array $message, array $metadata = []): array
    {
        $taskId = (string) Str::uuid();

        return [
            'id' => $taskId,
            'contextId' => $metadata['contextId'] ?? (string) Str::uuid(),
            'status' => [
                'state' => A2AState::SUBMITTED,
            ],
            'history' => [$message],
            'artifacts' => [],
            'metadata' => [
                ...$metadata,
                'agent_slug' => $agentSlug,
            ],
        ];
    }
}
