<?php

namespace App\Http\Controllers;

use App\Gate\GatekeeperWebhookRegistrar;
use App\Gate\TelegramGateClient;
use App\Http\Requests\TestGatekeeperBotRequest;
use App\Http\Requests\UpdateSettingsRequest;
use App\Support\AppSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class SettingsController extends Controller
{
    public function show(AppSettings $settings): JsonResponse
    {
        return response()->json([
            'data' => $settings->all(),
        ]);
    }

    public function update(UpdateSettingsRequest $request, AppSettings $settings): JsonResponse
    {
        return response()->json([
            'data' => $settings->update($request->validated()),
        ]);
    }

    public function testGatekeeper(TestGatekeeperBotRequest $request, AppSettings $settings): JsonResponse
    {
        $botToken = $this->nullableTrimmedString($request->input('bot_token'))
            ?? $settings->gatekeeperBotToken();
        $telegramChatId = $this->nullableTrimmedString($request->input('telegram_chat_id'))
            ?? $settings->gatekeeperTelegramChatId();

        if ($botToken === '') {
            throw ValidationException::withMessages([
                'bot_token' => 'Bot token is required.',
            ]);
        }

        if ($telegramChatId === '') {
            throw ValidationException::withMessages([
                'telegram_chat_id' => 'Telegram chat ID is required.',
            ]);
        }

        $result = (new TelegramGateClient($botToken))->sendTestMessage($telegramChatId);

        return response()->json([
            'data' => $result,
        ], $result['ok'] ? 200 : 422);
    }

    public function registerGatekeeperWebhook(
        TestGatekeeperBotRequest $request,
        AppSettings $settings,
        GatekeeperWebhookRegistrar $registrar,
    ): JsonResponse {
        $botToken = $this->resolveGatekeeperBotToken($request, $settings);
        $result = $registrar->set($botToken);

        if (! ($result['ok'] ?? false)) {
            return response()->json([
                'message' => $result['error'] ?? 'Could not register Telegram webhook.',
            ], 422);
        }

        return response()->json([
            'data' => [
                'webhook_url' => $result['webhook_url'],
            ],
        ]);
    }

    public function deleteGatekeeperWebhook(
        TestGatekeeperBotRequest $request,
        AppSettings $settings,
        GatekeeperWebhookRegistrar $registrar,
    ): JsonResponse|Response {
        $botToken = $this->resolveGatekeeperBotToken($request, $settings);
        $result = $registrar->delete($botToken);

        if (! ($result['ok'] ?? false)) {
            return response()->json([
                'message' => $result['error'] ?? 'Could not delete Telegram webhook.',
            ], 422);
        }

        return response()->noContent();
    }

    private function resolveGatekeeperBotToken(TestGatekeeperBotRequest $request, AppSettings $settings): string
    {
        $botToken = $this->nullableTrimmedString($request->input('bot_token'))
            ?? $settings->gatekeeperBotToken();

        if ($botToken === '') {
            throw ValidationException::withMessages([
                'bot_token' => 'Bot token is required.',
            ]);
        }

        return $botToken;
    }

    private function nullableTrimmedString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }
}
