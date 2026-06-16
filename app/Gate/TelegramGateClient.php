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
}
