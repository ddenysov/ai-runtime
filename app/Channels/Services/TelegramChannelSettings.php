<?php

namespace App\Channels\Services;

use Illuminate\Support\Str;

final class TelegramChannelSettings
{
    private const int SECRET_LENGTH = 32;

    /**
     * @param  array<string, mixed>  $settings
     */
    public static function webhookSecret(array $settings): string
    {
        $secret = $settings['webhook_secret'] ?? '';

        return is_string($secret) ? trim($secret) : '';
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public static function ensureWebhookSecret(array $settings): array
    {
        if (self::webhookSecret($settings) !== '') {
            return $settings;
        }

        $settings['webhook_secret'] = self::generateWebhookSecret();

        return $settings;
    }

    public static function generateWebhookSecret(): string
    {
        // Telegram secret_token: 1-256 chars, A-Za-z0-9_-
        return Str::random(self::SECRET_LENGTH);
    }
}
