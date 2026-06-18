<?php

namespace App\Channels\Http\Controllers;

use App\Channels\Models\AgentChannel;
use App\Http\Controllers\Controller;
use App\Telegram\Webhook\TelegramWebhookIngress;
use App\Telegram\Webhook\TelegramWebhookMessage;
use App\Telegram\Webhook\TelegramWebhookSkipReason;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TelegramAgentWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        AgentChannel $agentChannel,
        TelegramWebhookIngress $ingress,
    ): JsonResponse {
        $result = $ingress->handleMessage(
            TelegramWebhookMessage::fromHttp($agentChannel, $request),
        );

        return match ($result->skipReason) {
            TelegramWebhookSkipReason::InvalidChannel,
            TelegramWebhookSkipReason::ChannelNotFound => response()->json(['ok' => false], 404),
            TelegramWebhookSkipReason::InvalidSecret => response()->json(['ok' => false], 403),
            TelegramWebhookSkipReason::MissingBotToken => response()->json(['ok' => false, 'error' => 'missing_bot_token'], 503),
            default => response()->json(['ok' => true]),
        };
    }
}
