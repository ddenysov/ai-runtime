<?php

namespace Tests\Feature;

use App\Channels\Models\AgentChannel;
use App\Channels\Services\TelegramIncomingMessageHandler;
use App\Models\Agent;
use App\Models\AiProvider;
use App\Telegram\Webhook\TelegramWebhookIngress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class TelegramAgentWebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_accepts_valid_webhook_when_http_enabled(): void
    {
        config(['telegram.webhook.http_enabled' => true]);

        $channel = $this->createTelegramChannel();

        $handler = Mockery::mock(TelegramIncomingMessageHandler::class);
        $handler->shouldReceive('handle')->once();

        $this->app->instance(TelegramIncomingMessageHandler::class, $handler);
        $this->app->forgetInstance(TelegramWebhookIngress::class);

        $response = $this->postJson("/api/integrations/telegram/webhooks/{$channel->uuid}", [
            'update_id' => 1,
            'message' => [
                'text' => 'hello',
                'chat' => ['id' => 123],
            ],
        ]);

        $response->assertOk()->assertJson(['ok' => true]);
    }

    private function createTelegramChannel(): AgentChannel
    {
        $provider = AiProvider::query()->create([
            'slug' => uniqid('telegram-webhook-'),
            'name' => 'Telegram Webhook Provider',
            'type' => 'gemini',
            'credentials' => ['key' => 'test-key'],
        ]);

        $providerModel = $provider->models()->create([
            'slug' => uniqid('telegram-webhook-model-'),
            'name' => 'Telegram Webhook Model',
            'model' => uniqid('gemini-flash-'),
            'is_active' => true,
        ]);

        $agent = Agent::query()->create([
            'slug' => uniqid('telegram-webhook-agent-'),
            'name' => 'Telegram Webhook Agent',
            'ai_provider_model_id' => $providerModel->id,
            'instructions' => [],
            'is_active' => true,
        ]);

        return AgentChannel::query()->create([
            'uuid' => (string) Str::uuid(),
            'agent_id' => $agent->id,
            'name' => 'telegram-channel',
            'type' => 'telegram',
            'settings' => ['bot_token' => '123:abc'],
            'enabled' => true,
        ]);
    }
}
