<?php

namespace App\Http\Controllers;

use App\A2A\A2AState;
use App\A2A\SendMessageAction;
use App\A2A\TaskPayloadFactory;
use App\Models\A2AChildTask;
use App\Models\A2ATask;
use App\Models\Agent;
use App\Models\AgentChatMessage;
use App\Models\AgentRun;
use App\Models\AgentToolCall;
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
            $previewMessage = $threadMessages
                ->first(fn (AgentChatMessage $message): bool => $this->visibleMessageText($message) !== null);
            $latestRun = $latestRunsByContext->get($contextId);

            return [
                'context_id' => $contextId,
                'thread_id' => $chat->thread_id,
                'preview' => $previewMessage instanceof AgentChatMessage
                    ? Str::limit($this->visibleMessageText($previewMessage) ?? '', 180)
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
            ->map(function (AgentChatMessage $message): ?array {
                $content = $this->visibleMessageText($message);

                if ($content === null) {
                    return null;
                }

                return [
                    'id' => (string) $message->id,
                    'role' => $this->messageRole($message),
                    'content' => $content,
                    'status' => $this->messageStatus($message),
                    'created_at' => $message->created_at?->toISOString(),
                ];
            })
            ->filter()
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
            'activity' => $this->activity($run, $task),
            'messages' => $this->runMessages($run),
            'terminal' => $this->terminal($taskState, $runState),
        ];
    }

    private function runMessages(AgentRun $run): array
    {
        $contextId = $run->input['context_id'] ?? null;

        if (! is_string($contextId) || $contextId === '') {
            return [];
        }

        $threadId = "{$run->agent_slug}:{$contextId}";
        $latestUserMessageId = AgentChatMessage::query()
            ->where('thread_id', $threadId)
            ->where('role', 'user')
            ->where(function ($query): void {
                $query
                    ->whereNull('meta->type')
                    ->orWhere('meta->type', '!=', 'tool_call_result');
            })
            ->latest('id')
            ->value('id');

        if ($latestUserMessageId === null) {
            return [];
        }

        return AgentChatMessage::query()
            ->where('thread_id', $threadId)
            ->where('id', '>', (int) $latestUserMessageId)
            ->oldest('id')
            ->get()
            ->map(function (AgentChatMessage $message): ?array {
                $content = $this->visibleMessageText($message);

                if ($content === null || $content === '') {
                    return null;
                }

                return [
                    'id' => (string) $message->id,
                    'role' => $this->messageRole($message),
                    'content' => $content,
                    'status' => $this->messageStatus($message),
                    'created_at' => $message->created_at?->toISOString(),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function activity(AgentRun $run, ?A2ATask $task): array
    {
        $items = [
            [
                'id' => "run-{$run->id}",
                'type' => 'run',
                'title' => 'Agent run',
                'status' => $run->state,
                'detail' => $this->runActivityDetail($run),
                'timestamp' => $run->created_at?->toISOString(),
            ],
        ];

        $taskPayload = $task?->payload ?? [];
        $taskMessage = $this->messageText($taskPayload['status']['message'] ?? null);
        $artifact = $this->artifactText($taskPayload['artifacts'] ?? []);

        if ($task instanceof A2ATask) {
            $items[] = [
                'id' => "task-{$task->id}",
                'type' => 'task',
                'title' => $artifact === null ? 'Agent status updated' : 'Agent response received',
                'status' => $this->stateValue($taskPayload['status']['state'] ?? null),
                'detail' => $this->excerpt($artifact ?? $taskMessage ?? 'Waiting for the agent response.'),
                'timestamp' => $task->updated_at?->toISOString(),
            ];
        }

        $items = [
            ...$items,
            ...$this->chatToolActivity($run),
        ];

        $toolCalls = AgentToolCall::query()
            ->where('agent_run_id', $run->id)
            ->oldest()
            ->get();
        $childTasks = A2AChildTask::query()
            ->whereIn('tool_call_id', $toolCalls->pluck('id'))
            ->get()
            ->keyBy('tool_call_id');

        foreach ($toolCalls as $toolCall) {
            /** @var AgentToolCall $toolCall */
            $childTask = $childTasks->get($toolCall->id);
            $isSubagent = $toolCall->tool_name === 'remote_a2a_agent';
            $resultText = $this->toolResultText($toolCall);
            $errorText = $toolCall->error ?? $toolCall->error_kind;

            $items[] = [
                'id' => "tool-{$toolCall->id}",
                'type' => $isSubagent ? 'subagent_tool' : 'tool',
                'title' => $isSubagent ? 'Subagent tool called' : 'Tool called',
                'status' => $toolCall->state,
                'detail' => $this->excerpt($errorText ?? $resultText ?? $this->toolArgumentText($toolCall)),
                'timestamp' => $toolCall->updated_at?->toISOString(),
            ];

            if ($childTask instanceof A2AChildTask) {
                $items[] = [
                    'id' => "child-task-{$childTask->id}",
                    'type' => 'subagent',
                    'title' => "Subagent {$childTask->remote_agent_slug}",
                    'status' => $this->stateValue($childTask->state),
                    'detail' => $this->excerpt($childTask->last_error_message ?? $this->childTaskDetail($childTask)),
                    'timestamp' => $childTask->updated_at?->toISOString(),
                ];
            }
        }

        return collect($items)
            ->filter(fn (array $item): bool => $item['detail'] !== null || $item['type'] === 'run')
            ->values()
            ->all();
    }

    private function chatToolActivity(AgentRun $run): array
    {
        $contextId = $run->input['context_id'] ?? null;

        if (! is_string($contextId) || $contextId === '') {
            return [];
        }

        return AgentChatMessage::query()
            ->where('thread_id', "{$run->agent_slug}:{$contextId}")
            ->where('meta->type', 'tool_call_result')
            ->where('created_at', '>=', $run->created_at)
            ->oldest()
            ->get()
            ->map(function (AgentChatMessage $message): ?array {
                $detail = $this->toolCallResultText($message);

                if ($detail === null || $detail === '') {
                    return null;
                }

                return [
                    'id' => "chat-tool-{$message->id}",
                    'type' => 'tool',
                    'title' => 'Tool result received',
                    'status' => 'completed',
                    'detail' => $this->excerpt($detail),
                    'timestamp' => $message->created_at?->toISOString(),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function runActivityDetail(AgentRun $run): ?string
    {
        if ($run->last_error_message !== null) {
            return $this->excerpt($run->last_error_message);
        }

        return match ($run->state) {
            'submitted' => 'Queued for processing.',
            'working' => 'Agent is thinking.',
            'waiting_for_tool' => 'Waiting for a tool or subagent result.',
            'completed' => 'Run completed.',
            'failed' => 'Run failed.',
            default => null,
        };
    }

    private function toolArgumentText(AgentToolCall $toolCall): ?string
    {
        $arguments = is_array($toolCall->arguments) ? $toolCall->arguments : [];
        $agentSlug = $arguments['agent_slug'] ?? null;
        $message = $this->messageText($arguments['message'] ?? null);

        if (is_string($agentSlug) && $message !== null && $message !== '') {
            return "Calling {$agentSlug}: {$message}";
        }

        if (is_string($agentSlug)) {
            return "Calling {$agentSlug}.";
        }

        return $message !== null && $message !== '' ? $message : $toolCall->tool_name;
    }

    private function toolResultText(AgentToolCall $toolCall): ?string
    {
        $resultPayload = is_array($toolCall->result) ? $toolCall->result : null;
        $result = $this->messageText(
            $resultPayload['artifact'] ?? $resultPayload['error'] ?? $resultPayload,
        );

        return $result === null || $result === '' ? null : "Result received: {$result}";
    }

    private function childTaskDetail(A2AChildTask $childTask): string
    {
        $notification = is_array($childTask->last_notification) ? $childTask->last_notification : [];
        $artifact = $this->messageText($notification['artifact'] ?? null);
        $statusMessage = $this->messageText($notification['status']['message'] ?? null);

        if ($artifact !== null && $artifact !== '') {
            return "Response received: {$artifact}";
        }

        if ($statusMessage !== null && $statusMessage !== '') {
            return $statusMessage;
        }

        return 'Waiting for subagent result.';
    }

    private function stateValue(mixed $state): ?string
    {
        if ($state instanceof A2AState) {
            return $state->value;
        }

        return is_string($state) ? $state : null;
    }

    private function excerpt(?string $text, int $limit = 180): ?string
    {
        if ($text === null) {
            return null;
        }

        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);

        if ($text === '') {
            return null;
        }

        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        return mb_substr($text, 0, $limit - 3).'...';
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

    private function visibleMessageText(AgentChatMessage $message): ?string
    {
        if ($this->isToolCallResult($message)) {
            return $this->toolCallResultText($message);
        }

        $text = $this->messageText($message->content);

        return $text === '' ? null : $text;
    }

    private function messageRole(AgentChatMessage $message): string
    {
        if ($this->isToolCallResult($message)) {
            return 'tool';
        }

        return $message->role === 'user' ? 'user' : 'assistant';
    }

    private function messageStatus(AgentChatMessage $message): ?string
    {
        return $this->isToolCallResult($message) ? 'Tool result' : null;
    }

    private function isToolCallResult(AgentChatMessage $message): bool
    {
        return ($message->meta['type'] ?? null) === 'tool_call_result';
    }

    private function toolCallResultText(AgentChatMessage $message): ?string
    {
        $tools = $message->meta['tools'] ?? [];

        if (! is_array($tools) || $tools === []) {
            return null;
        }

        return collect($tools)
            ->map(function (mixed $tool): ?string {
                if (! is_array($tool)) {
                    return null;
                }

                $name = (string) ($tool['name'] ?? 'tool');
                $inputs = is_array($tool['inputs'] ?? null) ? $tool['inputs'] : [];
                $result = $this->decodeToolResult($tool['result'] ?? null);
                $lines = ["Tool called: {$name}"];

                $reason = $this->messageText($inputs['reason'] ?? null);
                $notation = $this->messageText($inputs['notation'] ?? null);

                if ($reason !== null && $reason !== '') {
                    $lines[] = "Reason: {$reason}";
                }

                if ($notation !== null && $notation !== '') {
                    $lines[] = "Input: {$notation}";
                }

                $summary = $this->toolResultSummary($result);

                if ($summary !== null) {
                    $lines[] = "Result received: {$summary}";
                }

                return implode("\n", $lines);
            })
            ->filter()
            ->implode("\n\n") ?: null;
    }

    private function decodeToolResult(mixed $result): mixed
    {
        if (! is_string($result)) {
            return $result;
        }

        try {
            return json_decode($result, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $result;
        }
    }

    private function toolResultSummary(mixed $result): ?string
    {
        if (! is_array($result)) {
            return $this->messageText($result);
        }

        $parts = [];

        if (array_key_exists('result', $result)) {
            $parts[] = 'value '.$this->messageText($result['result']);
        }

        if (array_key_exists('success', $result)) {
            $parts[] = ((bool) $result['success']) ? 'success' : 'failure';
        }

        $details = $this->messageText($result['details']['summary'] ?? null);

        if ($details !== null && $details !== '') {
            $parts[] = $details;
        }

        if ($parts !== []) {
            return implode(' | ', $parts);
        }

        return $this->messageText($result);
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
