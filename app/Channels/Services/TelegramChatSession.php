<?php

namespace App\Channels\Services;

final class TelegramChatSession
{
    public const NEW_CHAT_BUTTON_LABEL = 'New chat';

    public const NEW_CHAT_ACK_MESSAGE = 'New chat started. Send your next message.';

    public static function isNewChatRequest(string $text): bool
    {
        $trimmed = trim($text);

        if ($trimmed === self::NEW_CHAT_BUTTON_LABEL) {
            return true;
        }

        return strtolower($trimmed) === '/new';
    }

    /**
     * @return array<string, string>
     */
    public static function replyKeyboardMarkup(): array
    {
        return [
            'reply_markup' => json_encode([
                'keyboard' => [
                    [
                        ['text' => self::NEW_CHAT_BUTTON_LABEL],
                    ],
                ],
                'resize_keyboard' => true,
                'is_persistent' => true,
            ], JSON_THROW_ON_ERROR),
        ];
    }
}
