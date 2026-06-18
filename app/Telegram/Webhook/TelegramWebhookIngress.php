<?php

namespace App\Telegram\Webhook;

use App\Channels\Models\AgentChannel;
use App\Channels\Services\TelegramIncomingMessageHandler;
use Illuminate\Support\Facades\Log;
use Throwable;

final class TelegramWebhookIngress
{
    public function __construct(
        private readonly TelegramIncomingMessageHandler $handler,
        private readonly TelegramUpdateDeduplicator $deduplicator,
    ) {}

    public function handleMessage(TelegramWebhookMessage $message): TelegramWebhookIngressResult
    {
        return match ($message->type) {
            'agent_channel' => $this->handleAgentChannel($message),
            default => $this->skipUnknownType($message),
        };
    }

    private function handleAgentChannel(TelegramWebhookMessage $message): TelegramWebhookIngressResult
    {
        $channel = AgentChannel::query()
            ->where('uuid', $message->channelUuid)
            ->first();

        if (! $channel instanceof AgentChannel) {
            Log::warning('Telegram webhook skipped: agent channel not found.', [
                'channel_uuid' => $message->channelUuid,
                'request_id' => $message->requestId,
            ]);

            return TelegramWebhookIngressResult::skipped(TelegramWebhookSkipReason::ChannelNotFound);
        }

        if ($channel->type !== 'telegram' || ! $channel->enabled) {
            Log::warning('Telegram webhook skipped: invalid or disabled channel.', [
                'channel_uuid' => $message->channelUuid,
                'type' => $channel->type,
                'enabled' => $channel->enabled,
                'request_id' => $message->requestId,
            ]);

            return TelegramWebhookIngressResult::skipped(TelegramWebhookSkipReason::InvalidChannel);
        }

        $settings = is_array($channel->settings) ? $channel->settings : [];
        $botToken = isset($settings['bot_token']) && is_string($settings['bot_token'])
            ? trim($settings['bot_token'])
            : '';

        if ($botToken === '') {
            Log::warning('Telegram webhook skipped: missing bot token.', [
                'channel_uuid' => $message->channelUuid,
                'request_id' => $message->requestId,
            ]);

            return TelegramWebhookIngressResult::skipped(TelegramWebhookSkipReason::MissingBotToken);
        }

        $secret = isset($settings['webhook_secret']) && is_string($settings['webhook_secret'])
            ? trim($settings['webhook_secret'])
            : '';

        if ($secret !== '' && ! hash_equals($secret, $message->secretToken)) {
            Log::warning('Telegram webhook skipped: invalid secret token.', [
                'channel_uuid' => $message->channelUuid,
                'request_id' => $message->requestId,
            ]);

            return TelegramWebhookIngressResult::skipped(TelegramWebhookSkipReason::InvalidSecret);
        }

        if ($message->body === []) {
            return TelegramWebhookIngressResult::skipped(TelegramWebhookSkipReason::EmptyPayload);
        }

        $updateId = $message->updateId();

        if ($updateId !== null && $this->deduplicator->isDuplicate($message->channelUuid, $updateId)) {
            Log::info('Telegram webhook skipped: duplicate update.', [
                'channel_uuid' => $message->channelUuid,
                'update_id' => $updateId,
                'request_id' => $message->requestId,
            ]);

            return TelegramWebhookIngressResult::skipped(TelegramWebhookSkipReason::DuplicateUpdate);
        }

        try {
            $this->handler->handle($channel, $message->body);
        } catch (Throwable $exception) {
            Log::error('Telegram webhook processing failed.', [
                'channel_uuid' => $message->channelUuid,
                'request_id' => $message->requestId,
                'update_id' => $updateId,
                'exception' => $exception,
            ]);

            return TelegramWebhookIngressResult::failed();
        }

        return TelegramWebhookIngressResult::processed();
    }

    private function skipUnknownType(TelegramWebhookMessage $message): TelegramWebhookIngressResult
    {
        Log::warning('Telegram webhook skipped: unknown envelope type.', [
            'type' => $message->type,
            'channel_uuid' => $message->channelUuid,
            'request_id' => $message->requestId,
        ]);

        return TelegramWebhookIngressResult::skipped(TelegramWebhookSkipReason::UnknownType);
    }
}
