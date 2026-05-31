<?php

namespace App\Http\Controllers;

use App\A2A\A2AState;
use App\A2A\SendMessageAction;
use App\A2A\TaskPayloadFactory;
use App\Models\A2ATask;
use App\Models\Agent;
use App\Models\AgentRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AgentChatController extends Controller
{
    public function store(
        Request $request,
        Agent $agent,
        SendMessageAction $messages,
        TaskPayloadFactory $payloads,
    ): JsonResponse {
        if (! $agent->is_active) {
            return response()->json([
                'message' => 'Inactive agents cannot start chat runs.',
            ], 409);
        }

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:20000'],
        ]);

        $runId = (string) Str::uuid();
        $task = $messages->handle(
            $agent->slug,
            $payloads->userMessage($validated['message']),
            metadata: [
                'agent_run_id' => $runId,
                'source' => 'agent_chat',
            ],
        );

        $run = AgentRun::query()
            ->whereKey($runId)
            ->where('agent_slug', $agent->slug)
            ->firstOrFail();

        return response()->json([
            'run_id' => $runId,
            'task_id' => $task['id'],
            'stream_url' => route('agents.chat.events', [
                'agent' => $agent,
                'run' => $runId,
            ], false),
            'snapshot' => $this->snapshot($run),
        ], 202);
    }

    public function events(Request $request, Agent $agent, string $run): StreamedResponse
    {
        $timeoutSeconds = min(max($request->integer('timeout', 300), 5), 600);
        $deadline = now()->addSeconds($timeoutSeconds);

        return response()->stream(function () use ($agent, $run, $deadline): void {
            echo "retry: 1000\n\n";
            $this->flushStream();

            $lastPayload = null;

            while (! connection_aborted()) {
                $agentRun = AgentRun::query()
                    ->whereKey($run)
                    ->where('agent_slug', $agent->slug)
                    ->first();

                if (! $agentRun instanceof AgentRun) {
                    $this->sendEvent('failure', ['message' => 'Agent run was not found.']);

                    return;
                }

                $snapshot = $this->snapshot($agentRun);
                $payload = json_encode($snapshot, JSON_THROW_ON_ERROR);

                if ($payload !== $lastPayload) {
                    $this->sendEvent('message', $snapshot);
                    $lastPayload = $payload;
                }

                if ($snapshot['terminal'] === true) {
                    return;
                }

                if (now()->greaterThanOrEqualTo($deadline)) {
                    $this->sendEvent('timeout', [
                        'message' => 'Agent run is still working. Reconnect to continue watching.',
                        'snapshot' => $snapshot,
                    ]);

                    return;
                }

                usleep(500_000);
            }
        }, 200, [
            'Cache-Control' => 'no-cache',
            'Content-Type' => 'text/event-stream',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    private function snapshot(AgentRun $run): array
    {
        $run->refresh();

        $task = $this->taskForRun($run);
        $taskPayload = $task?->payload ?? [];
        $taskState = $taskPayload['status']['state'] ?? null;
        $runState = (string) $run->state;

        return [
            'run' => [
                'id' => $run->id,
                'state' => $runState,
                'attempts' => $run->attempts,
                'last_error_kind' => $run->last_error_kind,
                'last_error_message' => $run->last_error_message,
                'next_attempt_at' => $run->next_attempt_at?->toISOString(),
                'failed_at' => $run->failed_at?->toISOString(),
                'created_at' => $run->created_at?->toISOString(),
                'updated_at' => $run->updated_at?->toISOString(),
            ],
            'task' => [
                'id' => $taskPayload['id'] ?? $task?->id,
                'state' => $taskState,
                'message' => $this->messageText($taskPayload['status']['message'] ?? null),
                'artifact' => $this->artifactText($taskPayload['artifacts'] ?? []),
            ],
            'terminal' => $this->terminal($taskState, $runState),
        ];
    }

    private function taskForRun(AgentRun $run): ?A2ATask
    {
        $taskId = $run->input['a2a_task_id'] ?? null;

        if (! is_string($taskId)) {
            return null;
        }

        return A2ATask::query()->find($taskId);
    }

    private function terminal(?string $taskState, string $runState): bool
    {
        if ($taskState !== null && A2AState::isTerminal($taskState)) {
            return true;
        }

        return in_array($runState, ['completed', 'failed'], true);
    }

    private function artifactText(array $artifacts): ?string
    {
        $artifact = collect($artifacts)->last();

        return is_array($artifact) ? $this->messageText($artifact) : null;
    }

    private function messageText(mixed $message): ?string
    {
        if (! is_array($message)) {
            return null;
        }

        $parts = collect($message['parts'] ?? [])
            ->map(function (array $part): ?string {
                if (isset($part['text'])) {
                    return (string) $part['text'];
                }

                return isset($part['data'])
                    ? json_encode($part['data'], JSON_THROW_ON_ERROR)
                    : null;
            })
            ->filter()
            ->values();

        return $parts->isEmpty() ? null : $parts->implode("\n");
    }

    private function sendEvent(string $event, array $payload): void
    {
        if ($event !== 'message') {
            echo "event: {$event}\n";
        }

        echo 'data: '.json_encode($payload, JSON_THROW_ON_ERROR)."\n\n";
        $this->flushStream();
    }

    private function flushStream(): void
    {
        if (ob_get_level() > 0) {
            ob_flush();
        }

        flush();
    }
}
