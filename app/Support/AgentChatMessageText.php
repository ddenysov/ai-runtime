<?php

namespace App\Support;

use App\Models\AgentChatMessage;

class AgentChatMessageText
{
    public static function visible(AgentChatMessage $message): ?string
    {
        if (self::isToolCallResult($message)) {
            return null;
        }

        $text = self::extract($message->content);

        return $text === '' ? null : $text;
    }

    public static function isToolCallResult(AgentChatMessage $message): bool
    {
        return ($message->meta['type'] ?? null) === 'tool_call_result';
    }

    public static function extract(mixed $message): ?string
    {
        if ($message === null) {
            return null;
        }

        if (is_string($message) || is_numeric($message) || is_bool($message)) {
            return trim((string) $message);
        }

        if (! is_array($message)) {
            return null;
        }

        if (isset($message['text'])) {
            return self::extract($message['text']);
        }

        if (isset($message['content'])) {
            return self::extract($message['content']);
        }

        if (isset($message['data'])) {
            return json_encode($message['data'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }

        if (isset($message['parts']) && is_array($message['parts'])) {
            return collect($message['parts'])
                ->map(fn (mixed $part): ?string => self::extract($part))
                ->filter()
                ->implode("\n") ?: null;
        }

        if (array_is_list($message)) {
            return collect($message)
                ->map(fn (mixed $part): ?string => self::extract($part))
                ->filter()
                ->implode("\n") ?: null;
        }

        return trim(json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) ?: null;
    }
}
