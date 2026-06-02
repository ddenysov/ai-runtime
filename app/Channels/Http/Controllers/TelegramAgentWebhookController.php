<?php

namespace App\Channels\Http\Controllers;

use App\Channels\Models\AgentChannel;
use App\Channels\Services\TelegramIncomingMessageHandler;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TelegramAgentWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        AgentChannel $agentChannel,
        TelegramIncomingMessageHandler $handler,
    ): JsonResponse {
        if ($agentChannel->type !== 'telegram' || ! $agentChannel->enabled) {
            return response()->json(['ok' => false], 404);
        }

        $settings = is_array($agentChannel->settings) ? $agentChannel->settings : [];
        $botToken = isset($settings['bot_token']) && is_string($settings['bot_token'])
            ? trim($settings['bot_token'])
            : '';

        if ($botToken === '') {
            return response()->json(['ok' => false, 'error' => 'missing_bot_token'], 503);
        }

        $secret = isset($settings['webhook_secret']) && is_string($settings['webhook_secret'])
            ? trim($settings['webhook_secret'])
            : '';

        if ($secret !== '') {
            $header = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');
            if (! hash_equals($secret, $header)) {
                return response()->json(['ok' => false], 403);
            }
        }

        $payload = $request->all();

        if (is_array($payload) && $payload !== []) {
            $handler->handle($agentChannel, $payload);
        }

        return response()->json(['ok' => true]);
    }
}
