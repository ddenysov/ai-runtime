<?php

namespace App\Gate;

final class GateConfig
{
    /**
     * @param  array{enabled?: bool, bot_token?: string, telegram_chat_id?: string}|null  $fileConfig
     */
    public function __construct(
        private readonly bool $envEnabled,
        private readonly ?array $fileConfig,
    ) {}

    public static function load(string $storagePath, bool $envEnabled): self
    {
        $configPath = rtrim($storagePath, '/').'/config.json';
        $fileConfig = null;

        if (is_file($configPath)) {
            $decoded = json_decode((string) file_get_contents($configPath), true);

            if (is_array($decoded)) {
                $fileConfig = $decoded;
            }
        }

        return new self($envEnabled, $fileConfig);
    }

    public function isActive(): bool
    {
        if (! $this->envEnabled) {
            return false;
        }

        if (! is_array($this->fileConfig)) {
            return false;
        }

        if (($this->fileConfig['enabled'] ?? false) !== true) {
            return false;
        }

        return $this->botToken() !== '' && $this->telegramChatId() !== '';
    }

    public function botToken(): string
    {
        $token = $this->fileConfig['bot_token'] ?? '';

        return is_string($token) ? trim($token) : '';
    }

    public function telegramChatId(): string
    {
        $chatId = $this->fileConfig['telegram_chat_id'] ?? '';

        return is_string($chatId) || is_int($chatId) || is_float($chatId)
            ? trim((string) $chatId)
            : '';
    }

    /**
     * @return array{enabled: bool, bot_token: string, telegram_chat_id: string}|null
     */
    public function fileConfig(): ?array
    {
        return $this->fileConfig;
    }
}
