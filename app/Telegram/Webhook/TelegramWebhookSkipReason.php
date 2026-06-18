<?php

namespace App\Telegram\Webhook;

enum TelegramWebhookSkipReason
{
    case UnknownType;
    case ChannelNotFound;
    case InvalidChannel;
    case MissingBotToken;
    case InvalidSecret;
    case DuplicateUpdate;
    case EmptyPayload;
}
