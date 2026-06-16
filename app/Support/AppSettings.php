<?php

namespace App\Support;

use App\Gate\GateConfigPublisher;
use App\Models\AppSetting;

class AppSettings
{
    public const GROUP_PROMPTS = 'prompts';

    public const KEY_PROMPT_GENERATOR_AGENT_ID = 'prompt_generator_agent_id';

    public const GROUP_GATEKEEPER = 'gatekeeper';

    public const KEY_GATEKEEPER_ENABLED = 'enabled';

    public const KEY_GATEKEEPER_BOT_TOKEN = 'bot_token';

    public const KEY_GATEKEEPER_TELEGRAM_CHAT_ID = 'telegram_chat_id';

    public function __construct(
        private readonly GateConfigPublisher $gateConfigPublisher,
    ) {}

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        return [
            self::GROUP_PROMPTS => [
                self::KEY_PROMPT_GENERATOR_AGENT_ID => $this->nullableInt(
                    self::GROUP_PROMPTS,
                    self::KEY_PROMPT_GENERATOR_AGENT_ID,
                ),
            ],
            self::GROUP_GATEKEEPER => [
                self::KEY_GATEKEEPER_ENABLED => $this->boolean(
                    self::GROUP_GATEKEEPER,
                    self::KEY_GATEKEEPER_ENABLED,
                ),
                'bot_token_configured' => $this->botTokenConfigured(),
                self::KEY_GATEKEEPER_TELEGRAM_CHAT_ID => $this->stringValue(
                    self::GROUP_GATEKEEPER,
                    self::KEY_GATEKEEPER_TELEGRAM_CHAT_ID,
                ),
                'webhook_url' => $this->gatekeeperWebhookUrl(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, array<string, mixed>>
     */
    public function update(array $payload): array
    {
        if (array_key_exists(self::GROUP_PROMPTS, $payload) && is_array($payload[self::GROUP_PROMPTS])) {
            $prompts = $payload[self::GROUP_PROMPTS];

            if (array_key_exists(self::KEY_PROMPT_GENERATOR_AGENT_ID, $prompts)) {
                $this->set(
                    self::GROUP_PROMPTS,
                    self::KEY_PROMPT_GENERATOR_AGENT_ID,
                    $prompts[self::KEY_PROMPT_GENERATOR_AGENT_ID],
                );
            }
        }

        if (array_key_exists(self::GROUP_GATEKEEPER, $payload) && is_array($payload[self::GROUP_GATEKEEPER])) {
            $this->updateGatekeeper($payload[self::GROUP_GATEKEEPER]);
        }

        return $this->all();
    }

    public function promptGeneratorAgentId(): ?int
    {
        return $this->nullableInt(self::GROUP_PROMPTS, self::KEY_PROMPT_GENERATOR_AGENT_ID);
    }

    /**
     * @param  array<string, mixed>  $gatekeeper
     */
    private function updateGatekeeper(array $gatekeeper): void
    {
        if (array_key_exists(self::KEY_GATEKEEPER_ENABLED, $gatekeeper)) {
            $this->set(
                self::GROUP_GATEKEEPER,
                self::KEY_GATEKEEPER_ENABLED,
                (bool) $gatekeeper[self::KEY_GATEKEEPER_ENABLED],
            );
        }

        if (array_key_exists(self::KEY_GATEKEEPER_TELEGRAM_CHAT_ID, $gatekeeper)) {
            $this->set(
                self::GROUP_GATEKEEPER,
                self::KEY_GATEKEEPER_TELEGRAM_CHAT_ID,
                $this->nullableString($gatekeeper[self::KEY_GATEKEEPER_TELEGRAM_CHAT_ID]),
            );
        }

        if (array_key_exists(self::KEY_GATEKEEPER_BOT_TOKEN, $gatekeeper)) {
            $botToken = $this->nullableString($gatekeeper[self::KEY_GATEKEEPER_BOT_TOKEN]);

            if ($botToken !== null) {
                $this->set(self::GROUP_GATEKEEPER, self::KEY_GATEKEEPER_BOT_TOKEN, $botToken);
            }
        }

        $this->syncGatekeeperConfig();
    }

    private function syncGatekeeperConfig(): void
    {
        $enabled = $this->boolean(self::GROUP_GATEKEEPER, self::KEY_GATEKEEPER_ENABLED);
        $botToken = $this->stringValue(self::GROUP_GATEKEEPER, self::KEY_GATEKEEPER_BOT_TOKEN);
        $telegramChatId = $this->stringValue(self::GROUP_GATEKEEPER, self::KEY_GATEKEEPER_TELEGRAM_CHAT_ID);

        if (! $enabled && $botToken === '' && $telegramChatId === '') {
            $this->gateConfigPublisher->remove();

            return;
        }

        $this->gateConfigPublisher->publish([
            'enabled' => $enabled,
            'bot_token' => $botToken !== '' ? $botToken : null,
            'telegram_chat_id' => $telegramChatId !== '' ? $telegramChatId : null,
        ]);
    }

    private function gatekeeperWebhookUrl(): string
    {
        $publicUrl = config('app.public_url');

        if (! is_string($publicUrl) || trim($publicUrl) === '') {
            $publicUrl = (string) config('app.url');
        }

        $webhookPath = (string) config('gate.webhook_path', '/api/integrations/gatekeeper/telegram/webhook');

        return rtrim(trim($publicUrl), '/').$webhookPath;
    }

    private function botTokenConfigured(): bool
    {
        return $this->stringValue(self::GROUP_GATEKEEPER, self::KEY_GATEKEEPER_BOT_TOKEN) !== '';
    }

    private function boolean(string $group, string $key): bool
    {
        return (bool) $this->get($group, $key);
    }

    private function stringValue(string $group, string $key): string
    {
        $value = $this->get($group, $key);

        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return '';
        }

        return trim((string) $value);
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    private function nullableInt(string $group, string $key): ?int
    {
        $value = $this->get($group, $key);

        if ($value === null) {
            return null;
        }

        return (int) $value;
    }

    private function get(string $group, string $key): mixed
    {
        $setting = AppSetting::query()
            ->where('group', $group)
            ->where('key', $key)
            ->first();

        if ($setting === null) {
            return null;
        }

        return $setting->value;
    }

    private function set(string $group, string $key, mixed $value): void
    {
        if ($value === null || $value === '') {
            AppSetting::query()
                ->where('group', $group)
                ->where('key', $key)
                ->delete();

            return;
        }

        AppSetting::query()->updateOrCreate(
            [
                'group' => $group,
                'key' => $key,
            ],
            [
                'value' => $value,
            ],
        );
    }
}
