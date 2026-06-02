<?php

namespace App\Channels\Services;

use App\Channels\Models\AgentChannel;
use App\Libs\Telegram\MarkdownToHtml;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;
use Throwable;

final class TelegramOutboundMessenger
{
    private const CHUNK_LENGTH = 4000;

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

        $settings = is_array($channel->settings) ? $channel->settings : [];
        $botToken = isset($settings['bot_token']) && is_string($settings['bot_token'])
            ? trim($settings['bot_token'])
            : '';

        if ($botToken === '') {
            Log::warning('Telegram delivery skipped: channel has no bot_token.', [
                'agent_channel_uuid' => $channelUuid,
                'task_id' => $task['id'] ?? null,
            ]);

            return;
        }

        try {
            $this->sendAssistantReplyChunks(new Api($botToken), $chatId, $text);
        } catch (Throwable $exception) {
            Log::error('Telegram delivery failed.', [
                'agent_channel_uuid' => $channelUuid,
                'task_id' => $task['id'] ?? null,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function sendAssistantReplyChunks(Api $api, string $chatId, string $markdown): void
    {
        $html = MarkdownToHtml::convert($markdown);
        $htmlChunks = $this->chunkUtf8PreferNewlines($html, self::CHUNK_LENGTH);
        $htmlChunksSent = 0;

        foreach ($htmlChunks as $chunk) {
            $chunk = trim($chunk);

            if ($chunk === '') {
                continue;
            }

            try {
                $api->sendMessage([
                    'chat_id' => $chatId,
                    'text' => $chunk,
                    'parse_mode' => 'HTML',
                ]);
                $htmlChunksSent++;
            } catch (Throwable $exception) {
                if (! $this->isTelegramHtmlParseFailure($exception)) {
                    throw $exception;
                }

                Log::warning('Telegram HTML rejected; resending as plain text.', [
                    'chat_id' => $chatId,
                    'error' => $exception->getMessage(),
                ]);

                $this->sendPlainTextChunks($api, $chatId, $markdown);

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

    private function sendPlainTextChunks(Api $api, string $chatId, string $text): void
    {
        $text = trim($text);

        if ($text === '') {
            return;
        }

        $offset = 0;
        $length = mb_strlen($text);

        while ($offset < $length) {
            $chunk = mb_substr($text, $offset, self::CHUNK_LENGTH);
            $api->sendMessage([
                'chat_id' => $chatId,
                'text' => $chunk,
            ]);
            $offset += mb_strlen($chunk);
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
