<?php

namespace App\Http\Controllers;

use App\A2A\A2AState;
use App\A2A\SendMessageAction;
use App\A2A\TaskPayloadFactory;
use App\Models\A2ATask;
use App\Models\Agent;
use App\Models\AgentChatMessage;
use App\Models\AgentRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AgentChatController extends Controller
{
    public function index(Request $request, Agent $agent): JsonResponse
    {
        $perPage = min(max($request->integer('per_page', 10), 1), 50);
        $prefix = "{$agent->slug}:";
        $search = trim((string) $request->input('filter.search', ''));
        $sort = (string) $request->input('sort', '-last_message_at');

        $query = AgentChatMessage::query()
            ->select('thread_id')
            ->selectRaw('COUNT(*) as messages_count')
            ->selectRaw('MIN(created_at) as started_at')
            ->selectRaw('MAX(created_at) as last_message_at')
            ->where('thread_id', 'like', "{$prefix}%")
            ->groupBy('thread_id');

        if ($search !== '') {
            $matchingThreads = AgentChatMessage::query()
                ->select('thread_id')
                ->where('thread_id', 'like', "{$prefix}%")
                ->where(function ($query) use ($search): void {
                    $query
                        ->where('thread_id', 'like', "%{$search}%")
                        ->orWhere('content', 'like', "%{$search}%");
                });

            $query->whereIn('thread_id', $matchingThreads);
        }

        match ($sort) {
            'last_message_at' => $query->oldest('last_message_at'),
            '-started_at' => $query->latest('started_at'),
            'started_at' => $query->oldest('started_at'),
            '-messages_count' => $query->orderByDesc('messages_count'),
            'messages_count' => $query->orderBy('messages_count'),
            '-context_id' => $query->orderByDesc('thread_id'),
            'context_id' => $query->orderBy('thread_id'),
            default => $query->latest('last_message_at'),
        };

        $chats = $query
            ->paginate($perPage)
            ->appends($request->query());

        $threadIds = collect($chats->items())
            ->pluck('thread_id')
            ->values();
        $contextIds = $threadIds
            ->map(fn (string $threadId): string => Str::after($threadId, $prefix))
            ->values();
        $messagesByThread = AgentChatMessage::query()
            ->whereIn('thread_id', $threadIds)
            ->latest()
            ->get()
            ->groupBy('thread_id');
        $latestRunsByContext = AgentRun::query()
            ->where('agent_slug', $agent->slug)
            ->whereIn('input->context_id', $contextIds)
            ->latest()
            ->get()
            ->unique(fn (AgentRun $run): ?string => $run->input['context_id'] ?? null)
            ->keyBy(fn (AgentRun $run): ?string => $run->input['context_id'] ?? null);

        return response()->json($chats->through(function (object $chat) use ($agent, $messagesByThread, $latestRunsByContext, $prefix): array {
            $contextId = Str::after($chat->thread_id, $prefix);
            $threadMessages = $messagesByThread->get($chat->thread_id, collect());
            $previewMessage = $threadMessages->firstWhere('role', 'user') ?? $threadMessages->first();
            $latestRun = $latestRunsByContext->get($contextId);

            return [
                'context_id' => $contextId,
                'thread_id' => $chat->thread_id,
                'preview' => $previewMessage instanceof AgentChatMessage
                    ? Str::limit($this->messageText($previewMessage->content) ?? '', 180)
                    : '',
                'messages_count' => (int) $chat->messages_count,
                'started_at' => $chat->started_at,
                'last_message_at' => $chat->last_message_at,
                'latest_run' => $latestRun instanceof AgentRun ? [
                    'id' => $latestRun->id,
                    'state' => $latestRun->state,
                    'created_at' => $latestRun->created_at?->toISOString(),
                    'updated_at' => $latestRun->updated_at?->toISOString(),
                ] : null,
                'agent' => [
                    'id' => $agent->id,
                    'slug' => $agent->slug,
                    'name' => $agent->name,
                ],
            ];
        }));
    }

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
            'context_id' => ['sometimes', 'string', 'max:255'],
            'replace_failed_last_message' => ['sometimes', 'boolean'],
        ]);

        $runId = (string) Str::uuid();
        $contextId = $validated['context_id'] ?? (string) Str::uuid();

        if (($validated['replace_failed_last_message'] ?? false)
            && ! $this->replaceFailedLastUserMessage($agent, $contextId, $validated['message'])
        ) {
            return response()->json([
                'message' => 'The last message can only be replaced after a failed agent run.',
            ], 409);
        }

        $task = $messages->handle(
            $agent->slug,
            $payloads->userMessage($validated['message']),
            metadata: [
                'agent_run_id' => $runId,
                'contextId' => $contextId,
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
            'context_id' => $task['contextId'],
            'stream_url' => route('agents.chat.events', [
                'agent' => $agent,
                'run' => $runId,
            ], false),
            'snapshot' => $this->snapshot($run),
        ], 202);
    }

    private function replaceFailedLastUserMessage(Agent $agent, string $contextId, string $message): bool
    {
        $latestRun = $this->latestRunForContext($agent, $contextId);

        if (! $latestRun instanceof AgentRun || $latestRun->state !== 'failed') {
            return false;
        }

        $latestMessage = AgentChatMessage::query()
            ->where('thread_id', "{$agent->slug}:{$contextId}")
            ->latest('id')
            ->first();

        if (! $latestMessage instanceof AgentChatMessage || $latestMessage->role !== 'user') {
            return false;
        }

        DB::transaction(function () use ($agent, $contextId, $latestMessage, $message): void {
            $latestMessage->delete();

            AgentChatMessage::query()->create([
                'thread_id' => "{$agent->slug}:{$contextId}",
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'text',
                        'content' => $message,
                        'meta' => [],
                    ],
                ],
                'meta' => [
                    '__id' => 'msg_'.Str::uuid()->toString(),
                ],
            ]);
        });

        return true;
    }

    public function show(Agent $agent, string $contextId): JsonResponse
    {
        $latestRun = $this->latestRunForContext($agent, $contextId);
        $messages = AgentChatMessage::query()
            ->where('thread_id', "{$agent->slug}:{$contextId}")
            ->oldest()
            ->get()
            ->map(fn (AgentChatMessage $message): array => [
                'id' => (string) $message->id,
                'role' => $message->role === 'user' ? 'user' : 'assistant',
                'content' => $this->messageText($message->content) ?? '',
                'created_at' => $message->created_at?->toISOString(),
            ])
            ->values();

        if ($messages->isEmpty() && $latestRun instanceof AgentRun) {
            $messages->push([
                'id' => "run-{$latestRun->id}-user",
                'role' => 'user',
                'content' => $this->messageText($latestRun->input['message'] ?? null) ?? '',
                'created_at' => $latestRun->created_at?->toISOString(),
            ]);
        }

        return response()->json([
            'context_id' => $contextId,
            'messages' => $messages,
            'latest_run' => $latestRun instanceof AgentRun ? [
                'run_id' => $latestRun->id,
                'stream_url' => route('agents.chat.events', [
                    'agent' => $agent,
                    'run' => $latestRun->id,
                ], false),
                'snapshot' => $this->snapshot($latestRun),
            ] : null,
        ]);
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

    private function latestRunForContext(Agent $agent, string $contextId): ?AgentRun
    {
        return AgentRun::query()
            ->where('agent_slug', $agent->slug)
            ->where('input->context_id', $contextId)
            ->latest()
            ->first();
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
        if ($message === null) {
            return null;
        }

        if (is_string($message) || is_numeric($message) || is_bool($message)) {
            return trim((string) $message);
        }

        if (! is_array($message)) {
            return null;
        }

        if (isset($message['text'])) {
            return $this->messageText($message['text']);
        }

        if (isset($message['content'])) {
            return $this->messageText($message['content']);
        }

        if (isset($message['data'])) {
            return json_encode($message['data'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }

        if (isset($message['parts']) && is_array($message['parts'])) {
            return collect($message['parts'])
                ->map(fn (mixed $part): ?string => $this->messageText($part))
                ->filter()
                ->implode("\n") ?: null;
        }

        if (array_is_list($message)) {
            return collect($message)
                ->map(fn (mixed $part): ?string => $this->messageText($part))
                ->filter()
                ->implode("\n") ?: null;
        }

        return trim(json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) ?: null;
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
