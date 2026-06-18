<?php

namespace App\Telegram\Webhook;

enum TelegramWebhookIngressStatus
{
    case Processed;
    case Skipped;
    case Failed;
}
