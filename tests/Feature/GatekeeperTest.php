<?php

namespace Tests\Feature;

use App\Gate\GateState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GatekeeperTest extends TestCase
{
    use RefreshDatabase;

    private string $storagePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storagePath = storage_path('app/gate');

        if (! is_dir($this->storagePath)) {
            mkdir($this->storagePath, 0755, true);
        }

        file_put_contents($this->storagePath.'/config.json', json_encode([
            'enabled' => true,
            'bot_token' => '123:abc',
            'telegram_chat_id' => '999',
        ], JSON_THROW_ON_ERROR));

        GateState::make($this->storagePath)->close();

        config([
            'gate.enabled' => true,
            'gate.storage_path' => $this->storagePath,
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->storagePath.'/config.json');
        @unlink($this->storagePath.'/open_until');
        @unlink($this->storagePath.'/last_notified_at');

        config(['gate.enabled' => false]);

        parent::tearDown();
    }

    public function test_closed_gate_returns_nginx_like_404(): void
    {
        $response = $this->get('/');

        $response
            ->assertNotFound()
            ->assertHeader('Server', 'nginx')
            ->assertSee('404 Not Found', false)
            ->assertSee('nginx', false);
    }

    public function test_open_gate_allows_application_responses(): void
    {
        GateState::make($this->storagePath)->openForSeconds(120);

        $response = $this->get('/');

        $response->assertOk();
    }

    public function test_gatekeeper_webhook_opens_gate(): void
    {
        $response = $this->postJson('/api/integrations/gatekeeper/telegram/webhook', [
            'callback_query' => [
                'id' => 'cbq-1',
                'data' => 'gate:open',
                'message' => [
                    'chat' => [
                        'id' => '999',
                    ],
                ],
            ],
        ]);

        $response->assertOk()->assertJson(['ok' => true]);
        $this->assertTrue(GateState::make($this->storagePath)->isOpen());
    }

    public function test_gatekeeper_webhook_rejects_foreign_chat(): void
    {
        $response = $this->postJson('/api/integrations/gatekeeper/telegram/webhook', [
            'callback_query' => [
                'id' => 'cbq-1',
                'data' => 'gate:open',
                'message' => [
                    'chat' => [
                        'id' => '111',
                    ],
                ],
            ],
        ]);

        $response->assertForbidden();
        $this->assertFalse(GateState::make($this->storagePath)->isOpen());
    }
}
