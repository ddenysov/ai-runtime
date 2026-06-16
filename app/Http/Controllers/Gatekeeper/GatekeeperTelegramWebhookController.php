<?php

namespace App\Http\Controllers\Gatekeeper;

use App\Gate\GateConfig;
use App\Gate\GateState;
use App\Gate\TelegramGateClient;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class GatekeeperTelegramWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $storagePath = (string) config('gate.storage_path');
        $envEnabled = (bool) config('gate.enabled');
        $config = GateConfig::load($storagePath, $envEnabled);

        if (! $config->isActive()) {
            return response()->json(['ok' => false], 503);
        }

        /** @var array<string, mixed> $payload */
        $payload = $request->all();

        if (! isset($payload['callback_query']) || ! is_array($payload['callback_query'])) {
            return response()->json(['ok' => true]);
        }

        /** @var array<string, mixed> $callbackQuery */
        $callbackQuery = $payload['callback_query'];
        $data = $callbackQuery['data'] ?? null;

        if (! is_string($data) || $data !== 'gate:open') {
            return response()->json(['ok' => true]);
        }

        $message = $callbackQuery['message'] ?? null;
        $chat = is_array($message) ? ($message['chat'] ?? null) : null;
        $chatId = is_array($chat) ? ($chat['id'] ?? null) : null;
        $callbackQueryId = $callbackQuery['id'] ?? null;

        if (! is_string($callbackQueryId) || $callbackQueryId === '') {
            return response()->json(['ok' => true]);
        }

        $incomingChatId = is_string($chatId) || is_int($chatId) || is_float($chatId)
            ? trim((string) $chatId)
            : '';

        if ($incomingChatId === '' || $incomingChatId !== $config->telegramChatId()) {
            return response()->json(['ok' => false], 403);
        }

        $openSeconds = (int) config('gate.open_seconds', 120);
        GateState::make($storagePath)->openForSeconds($openSeconds);

        $client = new TelegramGateClient($config->botToken());
        $client->answerCallbackQuery(
            $callbackQueryId,
            'Login is open for '.(int) ($openSeconds / 60).' minutes.',
        );

        return response()->json(['ok' => true]);
    }
}
