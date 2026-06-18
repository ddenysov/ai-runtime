<?php

namespace Tests\Unit\Telegram\Webhook;

use App\Channels\Models\AgentChannel;
use App\Channels\Services\TelegramIncomingMessageHandler;
use App\Models\Agent;
use App\Models\AiProvider;
use App\Telegram\Webhook\TelegramUpdateDeduplicator;
use App\Telegram\Webhook\TelegramWebhookIngress;
use App\Telegram\Webhook\TelegramWebhookIngressStatus;
use App\Telegram\Webhook\TelegramWebhookMessage;
use App\Telegram\Webhook\TelegramWebhookSkipReason;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class TelegramWebhookIngressTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_processes_valid_agent_channel_message(): void
    {
        Cache::flush();

        $channel = $this->createTelegramChannel();
        $handler = Mockery::mock(TelegramIncomingMessageHandler::class);
        $handler->shouldReceive('handle')
            ->once()
            ->with(
                Mockery::on(fn (AgentChannel $received) => $received->uuid === $channel->uuid),
                Mockery::on(fn (array $payload) => ($payload['update_id'] ?? null) === 7),
            );

        $ingress = new TelegramWebhookIngress($handler, new TelegramUpdateDeduplicator);

        $result = $ingress->handleMessage(new TelegramWebhookMessage(
            version: 1,
            type: 'agent_channel',
            channelUuid: $channel->uuid,
            receivedAt: now()->toIso8601String(),
            requestId: 'req-1',
            secretToken: '',
            body: [
                'update_id' => 7,
                'message' => [
                    'text' => 'hello',
                    'chat' => ['id' => 123],
                ],
            ],
        ));

        $this->assertSame(TelegramWebhookIngressStatus::Processed, $result->status);
        $this->assertTrue($result->shouldDeleteFromQueue());
    }

    public function test_skips_invalid_secret(): void
    {
        $channel = $this->createTelegramChannel([
            'bot_token' => '123:abc',
            'webhook_secret' => 'expected-secret',
        ]);

        $handler = Mockery::mock(TelegramIncomingMessageHandler::class);
        $handler->shouldNotReceive('handle');

        $ingress = new TelegramWebhookIngress($handler, new TelegramUpdateDeduplicator);

        $result = $ingress->handleMessage(new TelegramWebhookMessage(
            version: 1,
            type: 'agent_channel',
            channelUuid: $channel->uuid,
            receivedAt: now()->toIso8601String(),
            requestId: 'req-2',
            secretToken: 'wrong-secret',
            body: ['update_id' => 8, 'message' => ['text' => 'hello', 'chat' => ['id' => 123]]],
        ));

        $this->assertSame(TelegramWebhookSkipReason::InvalidSecret, $result->skipReason);
        $this->assertTrue($result->shouldDeleteFromQueue());
    }

    public function test_skips_duplicate_update(): void
    {
        Cache::flush();

        $channel = $this->createTelegramChannel();
        $handler = Mockery::mock(TelegramIncomingMessageHandler::class);
        $handler->shouldReceive('handle')->once();

        $ingress = new TelegramWebhookIngress($handler, new TelegramUpdateDeduplicator);
        $payload = [
            'update_id' => 9,
            'message' => ['text' => 'hello', 'chat' => ['id' => 123]],
        ];

        $message = new TelegramWebhookMessage(
            version: 1,
            type: 'agent_channel',
            channelUuid: $channel->uuid,
            receivedAt: now()->toIso8601String(),
            requestId: 'req-3',
            secretToken: '',
            body: $payload,
        );

        $first = $ingress->handleMessage($message);
        $second = $ingress->handleMessage($message);

        $this->assertSame(TelegramWebhookIngressStatus::Processed, $first->status);
        $this->assertSame(TelegramWebhookSkipReason::DuplicateUpdate, $second->skipReason);
    }

    public function test_returns_failed_when_handler_throws(): void
    {
        Cache::flush();

        $channel = $this->createTelegramChannel();
        $handler = Mockery::mock(TelegramIncomingMessageHandler::class);
        $handler->shouldReceive('handle')->once()->andThrow(new RuntimeException('boom'));

        $ingress = new TelegramWebhookIngress($handler, new TelegramUpdateDeduplicator);

        $result = $ingress->handleMessage(new TelegramWebhookMessage(
            version: 1,
            type: 'agent_channel',
            channelUuid: $channel->uuid,
            receivedAt: now()->toIso8601String(),
            requestId: 'req-4',
            secretToken: '',
            body: ['update_id' => 10, 'message' => ['text' => 'hello', 'chat' => ['id' => 123]]],
        ));

        $this->assertSame(TelegramWebhookIngressStatus::Failed, $result->status);
        $this->assertFalse($result->shouldDeleteFromQueue());
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function createTelegramChannel(array $settings = []): AgentChannel
    {
        $provider = AiProvider::query()->create([
            'slug' => uniqid('telegram-ingress-'),
            'name' => 'Telegram Ingress Provider',
            'type' => 'gemini',
            'credentials' => ['key' => 'test-key'],
        ]);

        $providerModel = $provider->models()->create([
            'slug' => uniqid('telegram-ingress-model-'),
            'name' => 'Telegram Ingress Model',
            'model' => uniqid('gemini-flash-'),
            'is_active' => true,
        ]);

        $agent = Agent::query()->create([
            'slug' => uniqid('telegram-ingress-agent-'),
            'name' => 'Telegram Ingress Agent',
            'ai_provider_model_id' => $providerModel->id,
            'instructions' => [],
            'is_active' => true,
        ]);

        return AgentChannel::query()->create([
            'uuid' => (string) Str::uuid(),
            'agent_id' => $agent->id,
            'name' => 'telegram-channel',
            'type' => 'telegram',
            'settings' => $settings !== [] ? $settings : ['bot_token' => '123:abc'],
            'enabled' => true,
        ]);
    }
}
