<?php

namespace App\Gate;

final class TelegramGateClient
{
    public function __construct(
        private readonly string $botToken,
    ) {}

    public function sendVisitAlert(string $chatId, string $message): void
    {
        $this->post('sendMessage', [
            'chat_id' => $chatId,
            'text' => $message,
            'reply_markup' => json_encode([
                'inline_keyboard' => [[
                    [
                        'text' => 'Open login (2 min)',
                        'callback_data' => 'gate:open',
                    ],
                ]],
            ], JSON_THROW_ON_ERROR),
        ]);
    }

    public function answerCallbackQuery(string $callbackQueryId, string $text): void
    {
        $this->post('answerCallbackQuery', [
            'callback_query_id' => $callbackQueryId,
            'text' => $text,
        ]);
    }

    /**
     * @return array{ok: bool, message: string, response?: array<string, mixed>}
     */
    public function sendTestMessage(string $chatId, string $text = 'Gatekeeper test message'): array
    {
        return $this->postForResult('sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function post(string $method, array $payload): void
    {
        if ($this->botToken === '') {
            return;
        }

        $url = 'https://api.telegram.org/bot'.$this->botToken.'/'.$method;
        $body = http_build_query($payload);
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => $body,
                'timeout' => 5,
                'ignore_errors' => true,
            ],
        ]);

        @file_get_contents($url, false, $context);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, message: string, response?: array<string, mixed>}
     */
    private function postForResult(string $method, array $payload): array
    {
        if ($this->botToken === '') {
            return [
                'ok' => false,
                'message' => 'Bot token is not configured.',
            ];
        }

        $url = 'https://api.telegram.org/bot'.$this->botToken.'/'.$method;
        $body = http_build_query($payload);
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => $body,
                'timeout' => 10,
                'ignore_errors' => true,
            ],
        ]);

        $raw = @file_get_contents($url, false, $context);

        if ($raw === false) {
            return [
                'ok' => false,
                'message' => 'Could not reach Telegram API.',
            ];
        }

        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            return [
                'ok' => false,
                'message' => 'Invalid response from Telegram API.',
            ];
        }

        $ok = ($decoded['ok'] ?? false) === true;
        $description = $decoded['description'] ?? null;

        $result = [
            'ok' => $ok,
            'message' => $ok
                ? 'Test message sent successfully.'
                : (is_string($description) && $description !== ''
                    ? $description
                    : 'Telegram API returned an error.'),
            'response' => $decoded,
        ];

        return $result;
    }
}
