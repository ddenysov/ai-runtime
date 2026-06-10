<?php

namespace Tests\Feature;

use App\A2A\SendMessageAction;
use App\Channels\Models\AgentChannel;
use App\Channels\Models\AgentChannelThread;
use App\Channels\Services\AgentChannelDeliveryResolver;
use App\Jobs\RunScheduledAgent;
use App\Models\Agent;
use App\Models\AgentSchedule;
use App\Models\AiProvider;
use App\Models\AiProviderModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AgentChannelDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolver_uses_first_thread_of_first_enabled_deliverable_channel(): void
    {
        $agent = $this->createAgent();
        $telegram = $this->createTelegramChannel($agent, 'telegram-bot');
        $this->createThread($telegram, '111', 'context-first');
        $this->createThread($telegram, '222', 'context-second');

        $slack = $this->createChannel($agent, 'slack-alerts', 'slack');
        $this->createThread($slack, 'slack-user', 'slack-context');

        $destination = app(AgentChannelDeliveryResolver::class)->resolveForAgent($agent);

        $this->assertNotNull($destination);
        $this->assertSame('telegram', $destination->deliveryChannel['type']);
        $this->assertSame($telegram->uuid, $destination->deliveryChannel['agent_channel_uuid']);
        $this->assertSame('111', $destination->deliveryChannel['external_chat_id']);
        $this->assertSame('context-first', $destination->contextId);
    }

    public function test_resolver_skips_channels_without_a_destination(): void
    {
        $agent = $this->createAgent();
        $this->createChannel($agent, 'slack-only', 'slack');
        $telegram = $this->createTelegramChannel($agent, 'telegram-empty');

        $this->assertNull(app(AgentChannelDeliveryResolver::class)->resolveForAgent($agent));

        $this->createThread($telegram, '999', 'context-only');

        $destination = app(AgentChannelDeliveryResolver::class)->resolveForAgent($agent);

        $this->assertNotNull($destination);
        $this->assertSame('999', $destination->deliveryChannel['external_chat_id']);
    }

    public function test_scheduled_run_adds_delivery_channel_when_enabled(): void
    {
        $agent = $this->createAgent();
        $telegram = $this->createTelegramChannel($agent, 'telegram-bot');
        $this->createThread($telegram, '555', 'telegram-context');

        $schedule = AgentSchedule::query()->create([
            'uuid' => (string) Str::uuid(),
            'agent_id' => $agent->id,
            'name' => 'Morning report',
            'enabled' => true,
            'deliver_to_channel' => true,
            'timezone' => 'UTC',
            'schedule_type' => 'interval',
            'schedule_config' => ['every_minutes' => 60],
            'message' => 'Summarize inbox',
            'next_run_at' => now(),
        ]);

        $this->mock(SendMessageAction::class, function ($mock) use ($telegram): void {
            $mock->shouldReceive('handle')
                ->once()
                ->withArgs(function (string $agentSlug, array $message, ?array $configuration, array $metadata) use ($telegram): bool {
                    return $agentSlug === 'delivery-agent'
                        && ($metadata['delivery_channel']['type'] ?? null) === 'telegram'
                        && ($metadata['delivery_channel']['agent_channel_uuid'] ?? null) === $telegram->uuid
                        && ($metadata['delivery_channel']['external_chat_id'] ?? null) === '555'
                        && ($metadata['contextId'] ?? null) === 'telegram-context';
                })
                ->andReturn([
                    'id' => (string) Str::uuid(),
                    'contextId' => 'telegram-context',
                ]);
        });

        (new RunScheduledAgent($schedule->id))->handle(
            app(SendMessageAction::class),
            app(\App\A2A\TaskPayloadFactory::class),
            app(\App\Scheduling\AgentScheduleCalculator::class),
            app(AgentChannelDeliveryResolver::class),
        );
    }

    private function createAgent(): Agent
    {
        $provider = AiProvider::query()->create([
            'slug' => uniqid('delivery-gemini-'),
            'name' => 'Delivery Gemini',
            'type' => 'gemini',
            'credentials' => [
                'key' => 'test-key',
            ],
        ]);

        $providerModel = $provider->models()->create([
            'slug' => uniqid('delivery-model-'),
            'name' => 'Delivery Model',
            'model' => uniqid('gemini-flash-'),
            'is_active' => true,
        ]);

        return Agent::query()->create([
            'slug' => 'delivery-agent',
            'name' => 'Delivery Agent',
            'ai_provider_model_id' => $providerModel->id,
            'instructions' => [],
            'is_active' => true,
        ]);
    }

    private function createTelegramChannel(Agent $agent, string $name): AgentChannel
    {
        return $this->createChannel($agent, $name, 'telegram', [
            'bot_token' => 'test-token',
        ]);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function createChannel(Agent $agent, string $name, string $type, array $settings = []): AgentChannel
    {
        return AgentChannel::query()->create([
            'uuid' => (string) Str::uuid(),
            'agent_id' => $agent->id,
            'name' => $name,
            'type' => $type,
            'settings' => $settings,
            'enabled' => true,
        ]);
    }

    private function createThread(AgentChannel $channel, string $chatId, string $contextId): AgentChannelThread
    {
        return AgentChannelThread::query()->create([
            'agent_channel_id' => $channel->id,
            'external_chat_id' => $chatId,
            'context_id' => $contextId,
        ]);
    }
}
