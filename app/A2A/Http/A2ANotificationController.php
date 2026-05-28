<?php

namespace App\A2A\Http;

use App\A2A\A2AState;
use App\Jobs\ResumeParentAgentJob;
use App\Models\A2AChildTask;
use App\Models\A2ANotificationEvent;
use App\Models\AgentToolCall;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class A2ANotificationController
{
    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->all();
        $notification = $this->notificationFromPayload($payload);

        if ($notification === null) {
            return response()->json([
                'message' => 'Expected statusUpdate or artifactUpdate notification.',
            ], 422);
        }

        [$kind, $eventPayload] = $notification;
        $taskId = $this->stringValue($eventPayload['taskId'] ?? null);
        $contextId = $this->stringValue($eventPayload['contextId'] ?? null);

        $event = A2ANotificationEvent::query()->create([
            'kind' => $kind,
            'task_id' => $taskId,
            'context_id' => $contextId,
            'payload' => $payload,
            'headers' => $this->safeHeaders($request),
            'source_ip' => $request->ip(),
        ]);

        Log::channel('a2a_notifications')->info('A2A notification received.', [
            'event_id' => $event->id,
            'kind' => $kind,
            'task_id' => $taskId,
            'context_id' => $contextId,
            'payload' => $payload,
        ]);

        try {
            $this->applyToChildTask($kind, $eventPayload);

            $event->update(['processed_at' => now()]);
        } catch (Throwable $exception) {
            $event->update([
                'processed_at' => now(),
                'processing_error' => $exception->getMessage(),
            ]);

            Log::channel('a2a_notifications')->warning('A2A notification processing failed.', [
                'event_id' => $event->id,
                'kind' => $kind,
                'task_id' => $taskId,
                'context_id' => $contextId,
                'error' => $exception->getMessage(),
            ]);

            report($exception);
        }

        return response()->json([
            'accepted' => true,
            'event_id' => $event->id,
        ], 202);
    }

    private function applyToChildTask(string $kind, array $eventPayload): void
    {
        $taskId = $this->stringValue($eventPayload['taskId'] ?? null);

        if ($taskId === null) {
            return;
        }

        $childTask = A2AChildTask::query()
            ->where('remote_task_id', $taskId)
            ->when(
                $this->stringValue($eventPayload['contextId'] ?? null),
                fn ($query, string $contextId) => $query->where('remote_context_id', $contextId),
            )
            ->first();

        if ($childTask === null) {
            return;
        }

        if ($kind === 'artifactUpdate') {
            $this->completeChildTask($childTask, $eventPayload);

            return;
        }

        if ($kind === 'statusUpdate') {
            $this->updateChildTaskStatus($childTask, $eventPayload);
        }
    }

    private function completeChildTask(A2AChildTask $childTask, array $eventPayload): void
    {
        $artifact = is_array($eventPayload['artifact'] ?? null) ? $eventPayload['artifact'] : [];
        $result = [
            'remote_task_id' => $childTask->remote_task_id,
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
                'artifactUpdate' => $eventPayload,
            ],
        ]);

        ResumeParentAgentJob::dispatch($childTask->agent_run_id);
    }

    private function updateChildTaskStatus(A2AChildTask $childTask, array $eventPayload): void
    {
        $status = is_array($eventPayload['status'] ?? null) ? $eventPayload['status'] : [];
        $state = $this->stateFromStatus($status);

        if ($state === null) {
            $childTask->update([
                'last_notification' => [
                    'statusUpdate' => $eventPayload,
                ],
            ]);

            return;
        }

        if (in_array($state, [A2AState::FAILED, A2AState::CANCELED, A2AState::REJECTED], true)) {
            $message = $this->statusMessage($status);

            AgentToolCall::query()
                ->whereKey($childTask->tool_call_id)
                ->where('state', 'waiting')
                ->update([
                    'state' => 'failed',
                    'result' => ['error' => $status],
                    'error' => $message,
                ]);

            $childTask->update([
                'state' => $state,
                'last_error_message' => $message,
                'failed_at' => now(),
                'last_notification' => [
                    'statusUpdate' => $eventPayload,
                ],
            ]);

            ResumeParentAgentJob::dispatch($childTask->agent_run_id);

            return;
        }

        $updates = [
            'last_notification' => [
                'statusUpdate' => $eventPayload,
            ],
        ];

        if (! $state->terminal()) {
            $updates['state'] = $state;
        }

        $childTask->update($updates);
    }

    /**
     * @return array{string, array}|null
     */
    private function notificationFromPayload(array $payload): ?array
    {
        foreach (['statusUpdate', 'artifactUpdate'] as $kind) {
            if (is_array($payload[$kind] ?? null)) {
                return [$kind, $payload[$kind]];
            }
        }

        $legacyKind = $payload['kind'] ?? null;

        if (in_array($legacyKind, ['statusUpdate', 'artifactUpdate'], true)) {
            return [$legacyKind, collect($payload)->except('kind')->all()];
        }

        return null;
    }

    private function stateFromStatus(array $status): ?A2AState
    {
        $state = $this->stringValue($status['state'] ?? null);

        return $state === null ? null : A2AState::tryFrom($state);
    }

    private function statusMessage(array $status): string
    {
        $message = $status['message'] ?? null;

        if (is_array($message)) {
            foreach (($message['parts'] ?? []) as $part) {
                if (is_array($part) && isset($part['text'])) {
                    return (string) $part['text'];
                }
            }
        }

        return 'Remote A2A task reported a terminal status.';
    }

    private function stringValue(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function safeHeaders(Request $request): array
    {
        return collect($request->headers->all())
            ->except(['authorization', 'cookie', 'set-cookie'])
            ->map(fn (array $values): array => array_values($values))
            ->all();
    }
}
