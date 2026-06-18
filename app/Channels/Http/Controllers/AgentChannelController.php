<?php

namespace App\Channels\Http\Controllers;

use App\Channels\Http\Requests\DestroyAgentChannelRequest;
use App\Channels\Http\Requests\StoreAgentChannelRequest;
use App\Channels\Http\Requests\TestAgentChannelTelegramRequest;
use App\Channels\Http\Requests\UpdateAgentChannelRequest;
use App\Channels\Http\Resources\AgentChannelResource;
use App\Channels\Models\AgentChannel;
use App\Channels\Models\AgentChannelThread;
use App\Channels\Services\TelegramWebhookRegistrar;
use App\Gate\TelegramGateClient;
use App\Http\Controllers\Controller;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AgentChannelController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'agent_id' => ['sometimes', 'nullable', 'integer', 'exists:agents,id'],
        ]);

        $channels = AgentChannel::query()
            ->when(isset($validated['agent_id']), function ($query) use ($validated): void {
                $query->where('agent_id', (int) $validated['agent_id']);
            })
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => AgentChannelResource::collection($channels),
        ]);
    }

    public function store(StoreAgentChannelRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $channel = AgentChannel::query()->create([
            'uuid' => (string) Str::uuid(),
            'agent_id' => (int) $validated['agent_id'],
            'name' => (string) $validated['name'],
            'description' => $validated['description'] ?? null,
            'type' => (string) $validated['type'],
            'settings' => $request->settingsArray(),
            'metadata' => $validated['metadata'] ?? null,
            'enabled' => (bool) ($validated['enabled'] ?? true),
            'aggregate_version' => 0,
        ]);

        return response()->json([
            'data' => new AgentChannelResource($channel, true),
        ], 201);
    }

    public function show(AgentChannel $agentChannel): JsonResponse
    {
        return response()->json([
            'data' => new AgentChannelResource($agentChannel, true),
        ]);
    }

    public function update(UpdateAgentChannelRequest $request, AgentChannel $agentChannel): JsonResponse
    {
        $channel = DB::transaction(function () use ($request, $agentChannel): AgentChannel {
            /** @var AgentChannel $channel */
            $channel = AgentChannel::query()
                ->whereKey($agentChannel->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertExpectedVersion($channel, $request->expectedVersion());

            if ($request->has('name')) {
                $channel->name = (string) $request->validated('name');
            }

            if ($request->has('description')) {
                $description = $request->validated('description');
                $channel->description = ($description === '' || $description === null) ? null : (string) $description;
            }

            if ($request->has('type')) {
                $channel->type = (string) $request->validated('type');
            }

            if ($request->has('settings')) {
                $current = is_array($channel->settings) ? $channel->settings : [];
                $channel->settings = $this->mergeChannelSettings($current, $request->settingsArray());
            }

            if ($request->has('metadata')) {
                $channel->metadata = $request->validated('metadata');
            }

            if ($request->has('enabled')) {
                $channel->enabled = (bool) $request->validated('enabled');
            }

            $channel->aggregate_version = $channel->aggregate_version + 1;
            $channel->save();

            return $channel->refresh();
        });

        return response()->json([
            'data' => new AgentChannelResource($channel, true),
        ]);
    }

    public function setTelegramWebhook(AgentChannel $agentChannel, TelegramWebhookRegistrar $registrar): JsonResponse
    {
        $result = $registrar->set($agentChannel);

        if (! ($result['ok'] ?? false)) {
            return response()->json([
                'message' => $result['error'] ?? 'Could not register Telegram webhook.',
            ], 422);
        }

        return response()->json([
            'data' => [
                'webhook_url' => $result['webhook_url'],
            ],
        ]);
    }

    public function deleteTelegramWebhook(AgentChannel $agentChannel, TelegramWebhookRegistrar $registrar): JsonResponse
    {
        $result = $registrar->delete($agentChannel);

        if (! ($result['ok'] ?? false)) {
            return response()->json([
                'message' => $result['error'] ?? 'Could not delete Telegram webhook.',
            ], 422);
        }

        return response()->noContent();
    }

    public function testTelegram(TestAgentChannelTelegramRequest $request, AgentChannel $agentChannel): JsonResponse
    {
        if ($agentChannel->type !== 'telegram') {
            throw ValidationException::withMessages([
                'type' => 'Channel type must be telegram.',
            ]);
        }

        $botToken = $this->nullableTrimmedString($request->input('bot_token'))
            ?? $this->botTokenFromChannel($agentChannel);

        if ($botToken === '') {
            throw ValidationException::withMessages([
                'bot_token' => 'Bot token is required.',
            ]);
        }

        $telegramChatId = $this->nullableTrimmedString($request->input('telegram_chat_id'))
            ?? $this->defaultTelegramChatId($agentChannel);

        if ($telegramChatId === '') {
            throw ValidationException::withMessages([
                'telegram_chat_id' => 'Telegram chat ID is required. Message the bot once or enter a chat ID.',
            ]);
        }

        $message = 'Delivery channel test message ('.$agentChannel->name.')';
        $result = (new TelegramGateClient($botToken))->sendTestMessage($telegramChatId, $message);

        return response()->json([
            'data' => $result,
        ], $result['ok'] ? 200 : 422);
    }

    public function destroy(DestroyAgentChannelRequest $request, AgentChannel $agentChannel): Response
    {
        DB::transaction(function () use ($request, $agentChannel): void {
            /** @var AgentChannel $channel */
            $channel = AgentChannel::query()
                ->whereKey($agentChannel->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertExpectedVersion($channel, $request->expectedVersion());
            $channel->delete();
        });

        return response()->noContent();
    }

    private function assertExpectedVersion(AgentChannel $channel, int $expectedVersion): void
    {
        if ($channel->aggregate_version === $expectedVersion) {
            return;
        }

        throw new HttpResponseException(response()->json([
            'message' => 'Delivery channel version conflict.',
            'errors' => [
                'expected_version' => [
                    "Expected version {$expectedVersion}, current version is {$channel->aggregate_version}.",
                ],
            ],
        ], 409));
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    private function mergeChannelSettings(array $current, array $incoming): array
    {
        $merged = $current;

        foreach ($incoming as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            if (is_string($value) && trim($value) === '') {
                continue;
            }

            $merged[$key] = $value;
        }

        return $merged;
    }

    private function botTokenFromChannel(AgentChannel $channel): string
    {
        $settings = is_array($channel->settings) ? $channel->settings : [];

        return isset($settings['bot_token']) && is_string($settings['bot_token'])
            ? trim($settings['bot_token'])
            : '';
    }

    private function defaultTelegramChatId(AgentChannel $channel): string
    {
        $thread = AgentChannelThread::query()
            ->where('agent_channel_id', $channel->id)
            ->orderBy('id')
            ->first();

        if (! $thread instanceof AgentChannelThread) {
            return '';
        }

        return trim($thread->external_chat_id);
    }

    private function nullableTrimmedString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
