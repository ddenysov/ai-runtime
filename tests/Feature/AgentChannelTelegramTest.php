<?php

namespace Tests\Feature;

use App\Channels\Models\AgentChannel;
use App\Channels\Models\AgentChannelThread;
use App\Models\Agent;
use App\Models\AiProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AgentChannelTelegramTest extends TestCase
{
    use RefreshDatabase;

    public function test_requires_bot_token_to_test_telegram_channel(): void
    {
        $user = User::factory()->create();
        $channel = $this->createChannel($this->createAgent(), 'no-token', 'telegram');

        $response = $this->authenticatedJson($user)->postJson(
            "/api/agent-channels/{$channel->uuid}/telegram/test",
            ['telegram_chat_id' => '12345'],
        );

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['bot_token']);
    }

    public function test_requires_chat_id_when_channel_has_no_threads(): void
    {
        $user = User::factory()->create();
        $channel = $this->createTelegramChannel($this->createAgent(), 'with-token');

        $response = $this->authenticatedJson($user)->postJson(
            "/api/agent-channels/{$channel->uuid}/telegram/test",
        );

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['telegram_chat_id']);
    }

    public function test_uses_first_thread_chat_id_when_request_omits_it(): void
    {
        $user = User::factory()->create();
        $channel = $this->createTelegramChannel($this->createAgent(), 'thread-chat');
        $this->createThread($channel, '777001', 'context-test');

        $response = $this->authenticatedJson($user)->postJson(
            "/api/agent-channels/{$channel->uuid}/telegram/test",
        );

        $response
            ->assertUnprocessable()
            ->assertJsonPath('data.ok', false);
    }

    public function test_rejects_non_telegram_channel_type(): void
    {
        $user = User::factory()->create();
        $channel = $this->createChannel($this->createAgent(), 'slack-only', 'slack');

        $response = $this->authenticatedJson($user)->postJson(
            "/api/agent-channels/{$channel->uuid}/telegram/test",
            ['telegram_chat_id' => '12345'],
        );

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['type']);
    }

    public function test_list_includes_default_chat_id_from_first_thread(): void
    {
        $user = User::factory()->create();
        $agent = $this->createAgent();
        $channel = $this->createTelegramChannel($agent, 'listed');
        $this->createThread($channel, '888222', 'context-listed');

        $response = $this->authenticatedJson($user)->getJson('/api/agent-channels?agent_id='.$agent->id);

        $response
            ->assertOk()
            ->assertJsonPath('data.0.telegram_default_chat_id', '888222');
    }

    private function authenticatedJson(User $user): self
    {
        $token = 'test-csrf-token';

        return $this->actingAs($user)
            ->withSession(['_token' => $token])
            ->withHeader('X-CSRF-TOKEN', $token);
    }

    private function createAgent(): Agent
    {
        $provider = AiProvider::query()->create([
            'slug' => uniqid('channel-test-gemini-'),
            'name' => 'Channel Test Gemini',
            'type' => 'gemini',
            'credentials' => [
                'key' => 'test-key',
            ],
        ]);

        $providerModel = $provider->models()->create([
            'slug' => uniqid('channel-test-model-'),
            'name' => 'Channel Test Model',
            'model' => uniqid('gemini-flash-'),
            'is_active' => true,
        ]);

        return Agent::query()->create([
            'slug' => uniqid('channel-test-agent-'),
            'name' => 'Channel Test Agent',
            'ai_provider_model_id' => $providerModel->id,
            'instructions' => [],
            'is_active' => true,
        ]);
    }

    private function createTelegramChannel(Agent $agent, string $name): AgentChannel
    {
        return $this->createChannel($agent, $name, 'telegram', [
            'bot_token' => '123456:abc',
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
