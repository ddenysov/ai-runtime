<?php

namespace App\Channels\Console\Commands;

use App\Channels\Models\AgentChannel;
use App\Channels\Services\TelegramChannelSettings;
use App\Channels\Services\TelegramWebhookRegistrar;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use JsonException;
use Telegram\Bot\Api;
use Throwable;

class TelegramSetWebhookCommand extends Command
{
    protected $signature = 'telegram:set-webhook
        {channel_uuid? : agent_channels.uuid for a Telegram channel (omit with --all)}
        {--all : Register or delete webhooks for every Telegram channel that has a bot_token}
        {--delete : Remove the webhook via Telegram deleteWebhook}';

    protected $description = 'Call Telegram setWebhook (or deleteWebhook) for this app\'s webhook route (base URL from PUBLIC_APP_URL, else APP_URL).';

    public function handle(): int
    {
        $all = (bool) $this->option('all');
        $uuid = trim((string) ($this->argument('channel_uuid') ?? ''));

        if ($all && $uuid !== '') {
            $this->error('Do not pass channel_uuid together with --all.');

            return self::INVALID;
        }

        if (! $all && $uuid === '') {
            $this->error('Pass channel_uuid or use --all.');

            return self::INVALID;
        }

        $baseResult = $this->resolveHttpsBaseUrl();
        if (isset($baseResult['error'])) {
            $this->error($baseResult['error']);

            return self::FAILURE;
        }

        $base = $baseResult['base'];

        if ($all) {
            return $this->handleAll($base);
        }

        $channel = AgentChannel::query()->where('uuid', $uuid)->first();
        if ($channel === null) {
            $this->error('No agent channel found for uuid: '.$uuid);

            return self::FAILURE;
        }

        return $this->applyForChannel($channel, $base, printDetails: true);
    }

    /**
     * @return array{base: string}|array{error: string}
     */
    private function resolveHttpsBaseUrl(): array
    {
        $base = rtrim((string) config('app.public_url'), '/');
        if ($base === '') {
            return ['error' => 'config(app.public_url) is empty. Set PUBLIC_APP_URL or APP_URL in .env.'];
        }

        if (parse_url($base, PHP_URL_SCHEME) !== 'https') {
            return ['error' => 'Webhook base URL must use HTTPS (Telegram requirement). Current base: '.$base];
        }

        return ['base' => $base];
    }

    private function handleAll(string $base): int
    {
        /** @var Collection<int, AgentChannel> $channels */
        $channels = AgentChannel::query()
            ->where('type', 'telegram')
            ->orderBy('id')
            ->get();

        $failed = 0;
        $ok = 0;
        $skipped = 0;

        foreach ($channels as $channel) {
            $settings = is_array($channel->settings) ? $channel->settings : [];
            $botToken = isset($settings['bot_token']) && is_string($settings['bot_token'])
                ? trim($settings['bot_token'])
                : '';

            if ($botToken === '') {
                $this->warn('Skip (no bot_token): '.$channel->uuid.' — '.$channel->name);
                $skipped++;

                continue;
            }

            $code = $this->applyForChannel($channel, $base, printDetails: false);
            if ($code === self::SUCCESS) {
                $ok++;
            } else {
                $failed++;
            }
        }

        $this->newLine();
        $this->info(sprintf('Done: %d ok, %d failed, %d skipped (no token).', $ok, $failed, $skipped));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function applyForChannel(AgentChannel $channel, string $base, bool $printDetails): int
    {
        if ($channel->type !== 'telegram') {
            $this->error('Channel type must be telegram, got: '.$channel->type);

            return self::FAILURE;
        }

        $settings = is_array($channel->settings) ? $channel->settings : [];
        $botToken = isset($settings['bot_token']) && is_string($settings['bot_token'])
            ? trim($settings['bot_token'])
            : '';

        if ($botToken === '') {
            $this->error('Channel has no bot_token in settings. Save the token in the admin UI first.');

            return self::FAILURE;
        }

        if (! $this->option('delete') && TelegramChannelSettings::webhookSecret($settings) === '') {
            $channel->settings = TelegramChannelSettings::ensureWebhookSecret($settings);
            $channel->save();
            $settings = is_array($channel->settings) ? $channel->settings : [];
        }

        $webhookUrl = app(TelegramWebhookRegistrar::class)->webhookUrlFor($channel);

        if ($webhookUrl === null) {
            $this->error('Could not build webhook URL for '.$channel->uuid.' (check PUBLIC_APP_URL and TELEGRAM_WEBHOOK_AGENT_PATH).');

            return self::FAILURE;
        }

        $label = $channel->name.' ('.$channel->uuid.')';

        try {
            $api = new Api($botToken);

            if ($this->option('delete')) {
                $api->deleteWebhook();
                $this->info('[deleteWebhook] '.$label);

                return self::SUCCESS;
            }

            $params = [
                'url' => $webhookUrl,
            ];

            $secret = TelegramChannelSettings::webhookSecret($settings);

            if ($secret !== '') {
                $params['secret_token'] = $secret;
            }

            $api->setWebhook($params);

            $this->info('[setWebhook] '.$label);
            $this->line('  URL: '.$webhookUrl);

            if ($printDetails) {
                $info = $api->getWebhookInfo();
                $this->newLine();
                $this->line('Webhook info (from Telegram):');
                $this->line(json_encode($info->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            }
        } catch (JsonException|Throwable $exception) {
            $this->error($label.': '.$exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
