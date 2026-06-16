<?php

namespace Tests\Unit\Gate;

use App\Gate\GateConfig;
use App\Gate\GateFrontDoor;
use App\Gate\GateState;
use Tests\TestCase;

class GateFrontDoorTest extends TestCase
{
    private string $storagePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storagePath = storage_path('framework/testing/gate/'.uniqid('', true));
        mkdir($this->storagePath, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->storagePath);

        parent::tearDown();
    }

    public function test_allows_requests_when_gate_is_not_active(): void
    {
        $gate = GateFrontDoor::fromDefaults(
            storagePath: $this->storagePath,
            envEnabled: false,
        );

        $this->assertTrue($gate->shouldBootstrapApplication([
            'REQUEST_URI' => '/login',
            'REQUEST_METHOD' => 'GET',
        ]));
    }

    public function test_blocks_unauthenticated_requests_when_gate_is_closed(): void
    {
        $this->writeGateConfig();

        $gate = GateFrontDoor::fromDefaults(
            storagePath: $this->storagePath,
            envEnabled: true,
        );

        $this->assertFalse($gate->shouldBootstrapApplication([
            'REQUEST_URI' => '/login',
            'REQUEST_METHOD' => 'GET',
            'HTTP_USER_AGENT' => 'Mozilla/5.0',
        ]));
    }

    public function test_allows_requests_when_gate_is_open(): void
    {
        $this->writeGateConfig();
        GateState::make($this->storagePath)->openForSeconds(120);

        $gate = GateFrontDoor::fromDefaults(
            storagePath: $this->storagePath,
            envEnabled: true,
        );

        $this->assertTrue($gate->shouldBootstrapApplication([
            'REQUEST_URI' => '/',
            'REQUEST_METHOD' => 'GET',
        ]));
    }

    public function test_allows_requests_with_gate_auth_cookie_when_gate_is_closed(): void
    {
        $this->writeGateConfig();

        $gate = GateFrontDoor::fromDefaults(
            storagePath: $this->storagePath,
            envEnabled: true,
        );

        $this->assertTrue($gate->shouldBootstrapApplication([
            'REQUEST_URI' => '/api/agents',
            'REQUEST_METHOD' => 'GET',
            'HTTP_COOKIE' => 'gate_auth=1',
        ]));
    }

    public function test_blocks_requests_with_only_session_cookie_when_gate_is_closed(): void
    {
        $this->writeGateConfig();

        $gate = GateFrontDoor::fromDefaults(
            storagePath: $this->storagePath,
            envEnabled: true,
        );

        $this->assertFalse($gate->shouldBootstrapApplication([
            'REQUEST_URI' => '/',
            'REQUEST_METHOD' => 'GET',
            'HTTP_COOKIE' => 'laravel-session=abc123',
        ]));
    }

    public function test_allows_gatekeeper_and_agent_webhook_paths(): void
    {
        $this->writeGateConfig();

        $gate = GateFrontDoor::fromDefaults(
            storagePath: $this->storagePath,
            envEnabled: true,
        );

        $this->assertTrue($gate->shouldBootstrapApplication([
            'REQUEST_URI' => '/api/integrations/gatekeeper/telegram/webhook',
            'REQUEST_METHOD' => 'POST',
        ]));

        $this->assertTrue($gate->shouldBootstrapApplication([
            'REQUEST_URI' => '/api/integrations/telegram/webhooks/550e8400-e29b-41d4-a716-446655440000',
            'REQUEST_METHOD' => 'POST',
        ]));
    }

    public function test_gate_config_requires_env_flag_and_saved_credentials(): void
    {
        $this->writeGateConfig();

        $enabledConfig = GateConfig::load($this->storagePath, true);
        $disabledConfig = GateConfig::load($this->storagePath, false);

        $this->assertTrue($enabledConfig->isActive());
        $this->assertFalse($disabledConfig->isActive());
    }

    private function writeGateConfig(): void
    {
        file_put_contents($this->storagePath.'/config.json', json_encode([
            'enabled' => true,
            'bot_token' => '123:abc',
            'telegram_chat_id' => '999',
        ], JSON_THROW_ON_ERROR));
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $items = scandir($directory) ?: [];

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory.'/'.$item;

            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($directory);
    }
}
