<?php

namespace Tests\Unit\Channels\Services;

use App\Channels\Services\TelegramChannelSettings;
use Tests\TestCase;

class TelegramChannelSettingsTest extends TestCase
{
    public function test_ensure_webhook_secret_generates_when_missing(): void
    {
        $settings = TelegramChannelSettings::ensureWebhookSecret([
            'bot_token' => '123:abc',
        ]);

        $this->assertArrayHasKey('webhook_secret', $settings);
        $this->assertSame(32, strlen((string) $settings['webhook_secret']));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', (string) $settings['webhook_secret']);
    }

    public function test_ensure_webhook_secret_keeps_existing_value(): void
    {
        $settings = TelegramChannelSettings::ensureWebhookSecret([
            'webhook_secret' => 'keep-me',
        ]);

        $this->assertSame('keep-me', $settings['webhook_secret']);
    }

    public function test_ensure_webhook_secret_ignores_blank_value(): void
    {
        $settings = TelegramChannelSettings::ensureWebhookSecret([
            'webhook_secret' => '   ',
        ]);

        $this->assertNotSame('   ', $settings['webhook_secret']);
        $this->assertNotEmpty($settings['webhook_secret']);
    }
}
