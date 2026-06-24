<?php

namespace App\Http\Controllers;

use App\A2A\SendMessageAction;
use App\A2A\TaskPayloadFactory;
use App\Models\Agent;
use App\Models\AgentChatMessage;
use App\Models\AgentConversation;
use App\Models\AgentRun;
use App\Support\AgentChatMessageText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AgentConversationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max($request->integer('per_page', 10), 1), 50);
        $search = trim((string) $request->input('filter.search', ''));

        $query = AgentConversation::query()
            ->with(['firstAgent', 'secondAgent', 'nextAgent'])
            ->latest('updated_at');

        if ($search !== '') {
            $query->where(function ($query) use ($search): void {
                $query
                    ->where('starter_prompt', 'like', "%{$search}%")
                    ->orWhereHas('firstAgent', function ($query) use ($search): void {
                        $query
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('slug', 'like', "%{$search}%");
                    })
                    ->orWhereHas('secondAgent', function ($query) use ($search): void {
                        $query
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('slug', 'like', "%{$search}%");
                    });
            });
        }

        $conversations = $query
            ->paginate($perPage)
            ->appends($request->query());

        return response()->json($conversations->through(fn (AgentConversation $conversation): array => $this->summary($conversation)));
    }

    public function store(
        Request $request,
        SendMessageAction $messages,
        TaskPayloadFactory $payloads,
    ): JsonResponse {
        $validated = $request->validate([
            'first_agent_id' => ['required', 'integer', 'exists:agents,id'],
            'second_agent_id' => ['required', 'integer', 'different:first_agent_id', 'exists:agents,id'],
            'starter_prompt' => ['required', 'string', 'max:20000'],
        ]);

        $firstAgent = Agent::query()->findOrFail($validated['first_agent_id']);
        $secondAgent = Agent::query()->findOrFail($validated['second_agent_id']);

        if (! $firstAgent->is_active || ! $secondAgent->is_active) {
            return response()->json([
                'message' => 'Both agents must be active to start a conversation.',
            ], 409);
        }

        $conversation = AgentConversation::query()->create([
            'first_agent_id' => $firstAgent->id,
            'second_agent_id' => $secondAgent->id,
            'first_agent_context_id' => (string) Str::uuid(),
            'second_agent_context_id' => (string) Str::uuid(),
            'starter_prompt' => $validated['starter_prompt'],
            'next_agent_id' => $secondAgent->id,
        ]);

        $run = $this->startAgentRun(
            $conversation,
            $firstAgent,
            $validated['starter_prompt'],
            $messages,
            $payloads,
        );

        $conversation->load(['firstAgent', 'secondAgent', 'nextAgent']);

        return response()->json([
            ...$this->conversationPayload($conversation),
            'run' => $this->runPayload($firstAgent, $run),
        ], 201);
    }

    public function show(AgentConversation $agentConversation): JsonResponse
    {
        $agentConversation->load(['firstAgent', 'secondAgent', 'nextAgent']);

        return response()->json($this->conversationPayload($agentConversation));
    }

    public function advance(
        AgentConversation $agentConversation,
        SendMessageAction $messages,
        TaskPayloadFactory $payloads,
    ): JsonResponse {
        $agentConversation->load(['firstAgent', 'secondAgent', 'nextAgent']);

        $activeRun = $this->activeRun($agentConversation);

        if ($activeRun instanceof AgentRun) {
            return response()->json([
                'message' => 'Wait for the current agent run to finish before advancing.',
            ], 409);
        }

        $nextAgent = $agentConversation->nextAgent;

        if (! $nextAgent instanceof Agent || ! $nextAgent->is_active) {
            return response()->json([
                'message' => 'The next agent is inactive and cannot respond.',
            ], 409);
        }

        $speaker = $agentConversation->otherAgent($nextAgent);
        $relayMessage = $this->latestAssistantMessageText($speaker, $agentConversation->contextIdForAgent($speaker));

        if ($relayMessage === null || $relayMessage === '') {
            return response()->json([
                'message' => 'There is no agent message to relay yet.',
            ], 409);
        }

        $run = $this->startAgentRun(
            $agentConversation,
            $nextAgent,
            $relayMessage,
            $messages,
            $payloads,
        );

        $agentConversation->update([
            'next_agent_id' => $agentConversation->otherAgent($nextAgent)->id,
        ]);
        $agentConversation->load(['firstAgent', 'secondAgent', 'nextAgent']);

        return response()->json([
            ...$this->conversationPayload($agentConversation),
            'run' => $this->runPayload($nextAgent, $run),
        ], 202);
    }

    private function summary(AgentConversation $conversation): array
    {
        $messages = $this->timelineMessages($conversation);
        $preview = collect($messages)->last();

        return [
            'id' => $conversation->id,
            'starter_prompt' => $conversation->starter_prompt,
            'messages_count' => count($messages),
            'preview' => $preview['content'] ?? Str::limit($conversation->starter_prompt, 180),
            'first_agent' => $this->agentPayload($conversation->firstAgent),
            'second_agent' => $this->agentPayload($conversation->secondAgent),
            'next_agent' => $this->agentPayload($conversation->nextAgent),
            'created_at' => $conversation->created_at?->toISOString(),
            'updated_at' => $conversation->updated_at?->toISOString(),
        ];
    }

    private function conversationPayload(AgentConversation $conversation): array
    {
        $activeRun = $this->activeRun($conversation);
        $activeRunAgent = $activeRun instanceof AgentRun
            ? $this->agentForSlug($conversation, $activeRun->agent_slug)
            : null;

        return [
            'id' => $conversation->id,
            'starter_prompt' => $conversation->starter_prompt,
            'first_agent' => $this->agentPayload($conversation->firstAgent),
            'second_agent' => $this->agentPayload($conversation->secondAgent),
            'next_agent' => $this->agentPayload($conversation->nextAgent),
            'first_agent_context_id' => $conversation->first_agent_context_id,
            'second_agent_context_id' => $conversation->second_agent_context_id,
            'messages' => $this->timelineMessages($conversation),
            'active_run' => $activeRun instanceof AgentRun && $activeRunAgent instanceof Agent
                ? $this->runPayload($activeRunAgent, $activeRun)
                : null,
            'can_advance' => $activeRun === null && $this->latestAssistantMessageText(
                $conversation->otherAgent($conversation->nextAgent),
                $conversation->contextIdForAgent($conversation->otherAgent($conversation->nextAgent)),
            ) !== null,
        ];
    }

    private function timelineMessages(AgentConversation $conversation): array
    {
        $messages = [[
            'id' => 'starter',
            'kind' => 'starter',
            'agent_id' => null,
            'agent_name' => 'Starter',
            'content' => $conversation->starter_prompt,
            'created_at' => $conversation->created_at?->toISOString(),
        ]];

        foreach ([$conversation->firstAgent, $conversation->secondAgent] as $agent) {
            if (! $agent instanceof Agent) {
                continue;
            }

            $threadMessages = AgentChatMessage::query()
                ->where('thread_id', "{$agent->slug}:{$conversation->contextIdForAgent($agent)}")
                ->where('role', 'assistant')
                ->oldest('id')
                ->get();

            foreach ($threadMessages as $message) {
                $content = AgentChatMessageText::visible($message);

                if ($content === null || $content === '') {
                    continue;
                }

                $messages[] = [
                    'id' => (string) $message->id,
                    'kind' => 'agent',
                    'agent_id' => $agent->id,
                    'agent_name' => $agent->name,
                    'content' => $content,
                    'created_at' => $message->created_at?->toISOString(),
                ];
            }
        }

        usort($messages, function (array $left, array $right): int {
            if ($left['kind'] === 'starter') {
                return -1;
            }

            if ($right['kind'] === 'starter') {
                return 1;
            }

            return strcmp((string) ($left['created_at'] ?? ''), (string) ($right['created_at'] ?? ''));
        });

        return array_values($messages);
    }

    private function startAgentRun(
        AgentConversation $conversation,
        Agent $agent,
        string $message,
        SendMessageAction $messages,
        TaskPayloadFactory $payloads,
    ): AgentRun {
        $runId = (string) Str::uuid();
        $contextId = $conversation->contextIdForAgent($agent);

        $messages->handle(
            $agent->slug,
            $payloads->userMessage($message),
            metadata: [
                'agent_run_id' => $runId,
                'contextId' => $contextId,
                'source' => 'agent_conversation',
                'conversation_id' => $conversation->id,
            ],
        );

        return AgentRun::query()
            ->whereKey($runId)
            ->where('agent_slug', $agent->slug)
            ->firstOrFail();
    }

    private function activeRun(AgentConversation $conversation): ?AgentRun
    {
        $contexts = [
            [$conversation->firstAgent?->slug, $conversation->first_agent_context_id],
            [$conversation->secondAgent?->slug, $conversation->second_agent_context_id],
        ];

        foreach ($contexts as [$slug, $contextId]) {
            if (! is_string($slug) || $slug === '') {
                continue;
            }

            $run = AgentRun::query()
                ->where('agent_slug', $slug)
                ->where('input->context_id', $contextId)
                ->latest()
                ->first();

            if ($run instanceof AgentRun && ! in_array($run->state, ['completed', 'failed'], true)) {
                return $run;
            }
        }

        return null;
    }

    private function latestAssistantMessageText(Agent $agent, string $contextId): ?string
    {
        $message = AgentChatMessage::query()
            ->where('thread_id', "{$agent->slug}:{$contextId}")
            ->where('role', 'assistant')
            ->latest('id')
            ->first();

        if (! $message instanceof AgentChatMessage) {
            return null;
        }

        return AgentChatMessageText::visible($message);
    }

    private function agentForSlug(AgentConversation $conversation, string $slug): ?Agent
    {
        if ($conversation->firstAgent?->slug === $slug) {
            return $conversation->firstAgent;
        }

        if ($conversation->secondAgent?->slug === $slug) {
            return $conversation->secondAgent;
        }

        return null;
    }

    private function agentPayload(?Agent $agent): ?array
    {
        if (! $agent instanceof Agent) {
            return null;
        }

        return [
            'id' => $agent->id,
            'slug' => $agent->slug,
            'name' => $agent->name,
            'is_active' => $agent->is_active,
        ];
    }

    private function runPayload(Agent $agent, AgentRun $run): array
    {
        return [
            'agent_id' => $agent->id,
            'run_id' => $run->id,
            'context_id' => $run->input['context_id'] ?? null,
            'state' => $run->state,
            'stream_url' => route('agents.chat.events', [
                'agent' => $agent,
                'run' => $run->id,
            ], false),
        ];
    }
}
