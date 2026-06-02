<?php

namespace App\Channels\Services;

use App\Channels\Models\AgentChannel;
use App\Channels\Models\AgentChannelThread;
use App\Libs\Telegram\MarkdownToHtml;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;
use Throwable;

final class TelegramOutboundMessenger
{
    private const CHUNK_LENGTH = 4000;

    public function sendChatNotice(AgentChannel $channel, string $chatId, string $text): void
    {
        $text = trim($text);

        if ($text === '') {
            return;
        }

        $api = $this->resolveApi($channel);

        if ($api === null) {
            return;
        }

        try {
            $api->sendMessage([
                'chat_id' => $chatId,
                'text' => $text,
                ...TelegramChatSession::replyKeyboardMarkup(),
            ]);
        } catch (Throwable $exception) {
            Log::error('Telegram notice failed.', [
                'agent_channel_uuid' => $channel->uuid,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $task
     */
    public function deliverForTask(array $task, string $text): void
    {
        $text = trim($text);

        if ($text === '') {
            return;
        }

        $delivery = $task['metadata']['delivery_channel'] ?? null;

        if (! is_array($delivery) || ($delivery['type'] ?? null) !== 'telegram') {
            return;
        }

        $channelUuid = $delivery['agent_channel_uuid'] ?? null;
        $chatId = $delivery['external_chat_id'] ?? null;

        if (! is_string($channelUuid) || trim($channelUuid) === ''
            || ! is_string($chatId) || trim($chatId) === '') {
            return;
        }

        $channel = AgentChannel::query()
            ->where('uuid', $channelUuid)
            ->where('type', 'telegram')
            ->where('enabled', true)
            ->first();

        if ($channel === null) {
            return;
        }

        if ($this->isSupersededSession($channel, $chatId, $task)) {
            Log::info('Telegram delivery skipped: chat session was reset.', [
                'agent_channel_uuid' => $channelUuid,
                'task_id' => $task['id'] ?? null,
                'task_context_id' => $task['contextId'] ?? null,
            ]);

            return;
        }

        $api = $this->resolveApi($channel);

        if ($api === null) {
            Log::warning('Telegram delivery skipped: channel has no bot_token.', [
                'agent_channel_uuid' => $channelUuid,
                'task_id' => $task['id'] ?? null,
            ]);

            return;
        }

        try {
            $this->sendAssistantReplyChunks($api, $chatId, $text);
        } catch (Throwable $exception) {
            Log::error('Telegram delivery failed.', [
                'agent_channel_uuid' => $channelUuid,
                'task_id' => $task['id'] ?? null,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $task
     */
    private function isSupersededSession(AgentChannel $channel, string $chatId, array $task): bool
    {
        $taskContextId = $task['contextId'] ?? null;

        if (! is_string($taskContextId) || $taskContextId === '') {
            return false;
        }

        $thread = AgentChannelThread::query()
            ->where('agent_channel_id', $channel->id)
            ->where('external_chat_id', $chatId)
            ->first();

        return $thread === null || $thread->context_id !== $taskContextId;
    }

    private function resolveApi(AgentChannel $channel): ?Api
    {
        $settings = is_array($channel->settings) ? $channel->settings : [];
        $botToken = isset($settings['bot_token']) && is_string($settings['bot_token'])
            ? trim($settings['bot_token'])
            : '';

        if ($botToken === '') {
            return null;
        }

        return new Api($botToken);
    }

    private function sendAssistantReplyChunks(Api $api, string $chatId, string $markdown): void
    {
        $html = MarkdownToHtml::convert($markdown);
        $chunks = [];

        foreach ($this->chunkUtf8PreferNewlines($html, self::CHUNK_LENGTH) as $chunk) {
            $chunk = trim($chunk);

            if ($chunk !== '') {
                $chunks[] = $chunk;
            }
        }

        $lastIndex = count($chunks) - 1;

        foreach ($chunks as $index => $chunk) {
            $parameters = [
                'chat_id' => $chatId,
                'text' => $chunk,
                'parse_mode' => 'HTML',
            ];

            if ($index === $lastIndex) {
                $parameters = [
                    ...$parameters,
                    ...TelegramChatSession::replyKeyboardMarkup(),
                ];
            }

            try {
                $api->sendMessage($parameters);
            } catch (Throwable $exception) {
                if (! $this->isTelegramHtmlParseFailure($exception)) {
                    throw $exception;
                }

                Log::warning('Telegram HTML rejected; resending as plain text.', [
                    'chat_id' => $chatId,
                    'error' => $exception->getMessage(),
                ]);

                $this->sendPlainTextChunks($api, $chatId, $markdown, withKeyboard: true);

                return;
            }
        }
    }

    /**
     * @return list<string>
     */
    private function chunkUtf8PreferNewlines(string $text, int $maxLen): array
    {
        $chunks = [];
        $offset = 0;
        $length = mb_strlen($text);

        while ($offset < $length) {
            $remaining = $length - $offset;
            if ($remaining <= $maxLen) {
                $chunks[] = mb_substr($text, $offset, $remaining);
                break;
            }

            $chunk = mb_substr($text, $offset, $maxLen);
            $chunkLen = mb_strlen($chunk);
            $tailStart = max(0, $chunkLen - 500);
            $tail = mb_substr($chunk, $tailStart);
            $breakRel = mb_strrpos($tail, "\n\n");
            if ($breakRel !== false) {
                $breakAt = $tailStart + $breakRel + 2;
                if ($breakAt > (int) ($maxLen * 0.25)) {
                    $chunk = mb_substr($chunk, 0, $breakAt);
                }
            } else {
                $breakRel = mb_strrpos($tail, "\n");
                if ($breakRel !== false) {
                    $breakAt = $tailStart + $breakRel + 1;
                    if ($breakAt > (int) ($maxLen * 0.25)) {
                        $chunk = mb_substr($chunk, 0, $breakAt);
                    }
                }
            }

            $chunks[] = $chunk;
            $offset += mb_strlen($chunk);
        }

        return $chunks;
    }

    private function sendPlainTextChunks(Api $api, string $chatId, string $text, bool $withKeyboard = false): void
    {
        $text = trim($text);

        if ($text === '') {
            return;
        }

        $offset = 0;
        $length = mb_strlen($text);

        while ($offset < $length) {
            $chunk = mb_substr($text, $offset, self::CHUNK_LENGTH);
            $nextOffset = $offset + mb_strlen($chunk);
            $parameters = [
                'chat_id' => $chatId,
                'text' => $chunk,
            ];

            if ($withKeyboard && $nextOffset >= $length) {
                $parameters = [
                    ...$parameters,
                    ...TelegramChatSession::replyKeyboardMarkup(),
                ];
            }

            $api->sendMessage($parameters);
            $offset = $nextOffset;
        }
    }

    private function isTelegramHtmlParseFailure(Throwable $e): bool
    {
        $msg = strtolower($e->getMessage());

        return str_contains($msg, "can't parse entities")
            || str_contains($msg, 'can\'t parse entities')
            || (str_contains($msg, 'bad request') && str_contains($msg, 'parse'));
    }
}
