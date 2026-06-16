<?php

namespace App\Gate;

final class GateConfigPublisher
{
    public function __construct(
        private readonly string $storagePath,
    ) {}

    /**
     * @param  array{enabled?: bool, bot_token?: string|null, telegram_chat_id?: string|null}  $config
     */
    public function publish(array $config): void
    {
        $directory = rtrim($this->storagePath, '/');

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $existing = $this->readExistingConfig();
        $botToken = array_key_exists('bot_token', $config)
            ? $this->nullableString($config['bot_token'])
            : ($existing['bot_token'] ?? '');
        $telegramChatId = array_key_exists('telegram_chat_id', $config)
            ? $this->nullableString($config['telegram_chat_id'])
            : ($existing['telegram_chat_id'] ?? '');

        $payload = [
            'enabled' => (bool) ($config['enabled'] ?? ($existing['enabled'] ?? false)),
            'bot_token' => $botToken ?? '',
            'telegram_chat_id' => $telegramChatId ?? '',
        ];

        if ($payload['enabled'] && ($payload['bot_token'] === '' || $payload['telegram_chat_id'] === '')) {
            $payload['enabled'] = false;
        }

        file_put_contents(
            $directory.'/config.json',
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
            LOCK_EX,
        );
    }

    public function remove(): void
    {
        $path = rtrim($this->storagePath, '/').'/config.json';

        if (is_file($path)) {
            unlink($path);
        }
    }

    /**
     * @return array{enabled?: bool, bot_token?: string, telegram_chat_id?: string}
     */
    private function readExistingConfig(): array
    {
        $path = rtrim($this->storagePath, '/').'/config.json';

        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
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
}
