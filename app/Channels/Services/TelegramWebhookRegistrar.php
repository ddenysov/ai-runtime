<?php

namespace App\Channels\Services;

use App\Channels\Models\AgentChannel;
use JsonException;
use Telegram\Bot\Api;
use Throwable;

final class TelegramWebhookRegistrar
{
    public static function resolvePublicHttpsBase(): ?string
    {
        $base = rtrim((string) config('app.public_url'), '/');

        if ($base === '' || parse_url($base, PHP_URL_SCHEME) !== 'https') {
            return null;
        }

        return $base;
    }

    public function webhookUrlFor(AgentChannel $channel): ?string
    {
        if ($channel->type !== 'telegram') {
            return null;
        }

        $base = self::resolvePublicHttpsBase();

        if ($base === null) {
            return null;
        }

        $path = str_replace('{uuid}', $channel->uuid, (string) config('telegram.webhook.agent_channel_path'));

        return $base.$path;
    }

    /**
     * @return array{ok: true, webhook_url: string}|array{ok: false, error: string}
     */
    public function set(AgentChannel $channel): array
    {
        $base = self::resolvePublicHttpsBase();

        if ($base === null) {
            return ['ok' => false, 'error' => 'PUBLIC_APP_URL or APP_URL must be a valid HTTPS URL.'];
        }

        $botToken = $this->botToken($channel);

        if ($botToken === '') {
            return ['ok' => false, 'error' => 'Channel has no bot_token in settings.'];
        }

        if ($channel->type !== 'telegram') {
            return ['ok' => false, 'error' => 'Channel type must be telegram.'];
        }

        $webhookUrl = $this->webhookUrlFor($channel);

        if ($webhookUrl === null) {
            return ['ok' => false, 'error' => 'Could not build webhook URL.'];
        }

        try {
            $channel = $this->persistWebhookSecretIfMissing($channel);

            $api = new Api($botToken);
            $params = ['url' => $webhookUrl];
            $secret = TelegramChannelSettings::webhookSecret(
                is_array($channel->settings) ? $channel->settings : [],
            );

            if ($secret !== '') {
                $params['secret_token'] = $secret;
            }

            $api->setWebhook($params);

            return ['ok' => true, 'webhook_url' => $webhookUrl];
        } catch (JsonException|Throwable $exception) {
            return ['ok' => false, 'error' => $exception->getMessage()];
        }
    }

    /**
     * @return array{ok: true}|array{ok: false, error: string}
     */
    public function delete(AgentChannel $channel): array
    {
        $botToken = $this->botToken($channel);

        if ($botToken === '') {
            return ['ok' => false, 'error' => 'Channel has no bot_token in settings.'];
        }

        try {
            (new Api($botToken))->deleteWebhook();

            return ['ok' => true];
        } catch (Throwable $exception) {
            return ['ok' => false, 'error' => $exception->getMessage()];
        }
    }

    private function botToken(AgentChannel $channel): string
    {
        $settings = is_array($channel->settings) ? $channel->settings : [];

        return isset($settings['bot_token']) && is_string($settings['bot_token'])
            ? trim($settings['bot_token'])
            : '';
    }

    private function persistWebhookSecretIfMissing(AgentChannel $channel): AgentChannel
    {
        if ($channel->type !== 'telegram') {
            return $channel;
        }

        $settings = is_array($channel->settings) ? $channel->settings : [];

        if (TelegramChannelSettings::webhookSecret($settings) !== '') {
            return $channel;
        }

        $channel->settings = TelegramChannelSettings::ensureWebhookSecret($settings);
        $channel->save();

        return $channel->refresh();
    }
}
