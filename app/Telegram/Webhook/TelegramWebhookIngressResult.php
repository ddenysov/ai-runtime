<?php

namespace App\Telegram\Webhook;

final class TelegramWebhookIngressResult
{
    public function __construct(
        public readonly TelegramWebhookIngressStatus $status,
        public readonly ?TelegramWebhookSkipReason $skipReason = null,
    ) {}

    public static function processed(): self
    {
        return new self(TelegramWebhookIngressStatus::Processed);
    }

    public static function skipped(TelegramWebhookSkipReason $reason): self
    {
        return new self(TelegramWebhookIngressStatus::Skipped, $reason);
    }

    public static function failed(): self
    {
        return new self(TelegramWebhookIngressStatus::Failed);
    }

    public function shouldDeleteFromQueue(): bool
    {
        return $this->status !== TelegramWebhookIngressStatus::Failed;
    }
}
