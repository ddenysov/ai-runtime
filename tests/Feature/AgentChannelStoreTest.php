<?php

namespace Tests\Feature;

use App\Channels\Models\AgentChannel;
use App\Models\Agent;
use App\Models\AiProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentChannelStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_telegram_channel_auto_generates_webhook_secret(): void
    {
        $user = User::factory()->create();
        $agent = $this->createAgent();
        $token = 'test-csrf-token';

        $response = $this->actingAs($user)
            ->withSession(['_token' => $token])
            ->withHeader('X-CSRF-TOKEN', $token)
            ->postJson('/api/agent-channels', [
                'agent_id' => $agent->id,
                'name' => 'tg-auto-secret',
                'type' => 'telegram',
                'settings' => [
                    'bot_token' => '123456:abc',
                ],
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.type', 'telegram');

        $secret = $response->json('data.settings.webhook_secret');
        $this->assertIsString($secret);
        $this->assertSame(32, strlen($secret));

        $channel = AgentChannel::query()->where('name', 'tg-auto-secret')->first();
        $this->assertInstanceOf(AgentChannel::class, $channel);
        $this->assertSame($secret, $channel->settings['webhook_secret'] ?? null);
    }

    public function test_creating_non_telegram_channel_does_not_generate_webhook_secret(): void
    {
        $user = User::factory()->create();
        $agent = $this->createAgent();
        $token = 'test-csrf-token';

        $response = $this->actingAs($user)
            ->withSession(['_token' => $token])
            ->withHeader('X-CSRF-TOKEN', $token)
            ->postJson('/api/agent-channels', [
                'agent_id' => $agent->id,
                'name' => 'slack-channel',
                'type' => 'slack',
                'settings' => [
                    'bot_token' => 'xoxb-test',
                ],
            ]);

        $response->assertCreated();

        $settings = $response->json('data.settings');
        $this->assertIsArray($settings);
        $this->assertArrayNotHasKey('webhook_secret', $settings);
    }

    public function test_creating_telegram_channel_keeps_provided_webhook_secret(): void
    {
        $user = User::factory()->create();
        $agent = $this->createAgent();
        $token = 'test-csrf-token';

        $response = $this->actingAs($user)
            ->withSession(['_token' => $token])
            ->withHeader('X-CSRF-TOKEN', $token)
            ->postJson('/api/agent-channels', [
                'agent_id' => $agent->id,
                'name' => 'tg-custom-secret',
                'type' => 'telegram',
                'settings' => [
                    'bot_token' => '123456:abc',
                    'webhook_secret' => 'custom-secret-token',
                ],
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.settings.webhook_secret', 'custom-secret-token');
    }

    private function createAgent(): Agent
    {
        $provider = AiProvider::query()->create([
            'slug' => uniqid('channel-store-gemini-'),
            'name' => 'Channel Store Gemini',
            'type' => 'gemini',
            'credentials' => [
                'key' => 'test-key',
            ],
        ]);

        $providerModel = $provider->models()->create([
            'slug' => uniqid('channel-store-model-'),
            'name' => 'Channel Store Model',
            'model' => uniqid('gemini-flash-'),
            'is_active' => true,
        ]);

        return Agent::query()->create([
            'slug' => uniqid('channel-store-agent-'),
            'name' => 'Channel Store Agent',
            'ai_provider_model_id' => $providerModel->id,
            'instructions' => [],
            'is_active' => true,
        ]);
    }
}
