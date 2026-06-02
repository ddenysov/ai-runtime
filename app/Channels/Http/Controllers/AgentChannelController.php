<?php

namespace App\Channels\Http\Controllers;

use App\Channels\Http\Requests\DestroyAgentChannelRequest;
use App\Channels\Http\Requests\StoreAgentChannelRequest;
use App\Channels\Http\Requests\UpdateAgentChannelRequest;
use App\Channels\Http\Resources\AgentChannelResource;
use App\Channels\Models\AgentChannel;
use App\Channels\Services\TelegramWebhookRegistrar;
use App\Http\Controllers\Controller;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
}
