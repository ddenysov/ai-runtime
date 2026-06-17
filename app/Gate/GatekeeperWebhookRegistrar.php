<?php

namespace App\Gate;

use App\Channels\Services\TelegramWebhookRegistrar;
use JsonException;
use Telegram\Bot\Api;
use Throwable;

final class GatekeeperWebhookRegistrar
{
    public function webhookUrl(): ?string
    {
        $base = TelegramWebhookRegistrar::resolvePublicHttpsBase();

        if ($base === null) {
            return null;
        }

        $webhookPath = (string) config('gate.webhook_path', '/api/integrations/gatekeeper/telegram/webhook');

        return $base.$webhookPath;
    }

    /**
     * @return array{ok: true, webhook_url: string}|array{ok: false, error: string}
     */
    public function set(string $botToken): array
    {
        $botToken = trim($botToken);

        if ($botToken === '') {
            return ['ok' => false, 'error' => 'Bot token is required.'];
        }

        $webhookUrl = $this->webhookUrl();

        if ($webhookUrl === null) {
            return ['ok' => false, 'error' => 'PUBLIC_APP_URL or APP_URL must be a valid HTTPS URL.'];
        }

        try {
            (new Api($botToken))->setWebhook(['url' => $webhookUrl]);

            return ['ok' => true, 'webhook_url' => $webhookUrl];
        } catch (JsonException|Throwable $exception) {
            return ['ok' => false, 'error' => $exception->getMessage()];
        }
    }

    /**
     * @return array{ok: true}|array{ok: false, error: string}
     */
    public function delete(string $botToken): array
    {
        $botToken = trim($botToken);

        if ($botToken === '') {
            return ['ok' => false, 'error' => 'Bot token is required.'];
        }

        try {
            (new Api($botToken))->deleteWebhook();

            return ['ok' => true];
        } catch (Throwable $exception) {
            return ['ok' => false, 'error' => $exception->getMessage()];
        }
    }
}
