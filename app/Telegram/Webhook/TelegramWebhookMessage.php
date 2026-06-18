<?php

namespace App\Telegram\Webhook;

use App\Channels\Models\AgentChannel;
use Illuminate\Http\Request;
use InvalidArgumentException;
use JsonException;

final class TelegramWebhookMessage
{
    /**
     * @param  array<string, mixed>  $body
     */
    public function __construct(
        public readonly int $version,
        public readonly string $type,
        public readonly string $channelUuid,
        public readonly string $receivedAt,
        public readonly string $requestId,
        public readonly string $secretToken,
        public readonly array $body,
    ) {}

    public static function fromJson(string $json): self
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Telegram webhook envelope is not valid JSON.', 0, $exception);
        }

        if (! is_array($decoded)) {
            throw new InvalidArgumentException('Telegram webhook envelope must decode to an object.');
        }

        return self::fromEnvelope($decoded);
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    public static function fromEnvelope(array $envelope): self
    {
        $version = $envelope['version'] ?? null;
        $type = $envelope['type'] ?? null;
        $channelUuid = $envelope['channel_uuid'] ?? null;
        $receivedAt = $envelope['received_at'] ?? null;
        $requestId = $envelope['request_id'] ?? null;
        $headers = $envelope['headers'] ?? null;
        $body = $envelope['body'] ?? null;

        if (! is_int($version) || $version < 1) {
            throw new InvalidArgumentException('Telegram webhook envelope is missing a valid version.');
        }

        if (! is_string($type) || trim($type) === '') {
            throw new InvalidArgumentException('Telegram webhook envelope is missing type.');
        }

        if (! is_string($channelUuid) || trim($channelUuid) === '') {
            throw new InvalidArgumentException('Telegram webhook envelope is missing channel_uuid.');
        }

        if (! is_string($receivedAt)) {
            $receivedAt = '';
        }

        if (! is_string($requestId)) {
            $requestId = '';
        }

        $secretToken = '';

        if (is_array($headers)) {
            $secret = $headers['x-telegram-bot-api-secret-token'] ?? null;

            if (is_string($secret)) {
                $secretToken = trim($secret);
            }
        }

        if (! is_array($body)) {
            $body = [];
        }

        return new self(
            version: $version,
            type: $type,
            channelUuid: trim($channelUuid),
            receivedAt: $receivedAt,
            requestId: $requestId,
            secretToken: $secretToken,
            body: $body,
        );
    }

    public static function fromHttp(AgentChannel $channel, Request $request): self
    {
        $payload = $request->all();

        return new self(
            version: 1,
            type: 'agent_channel',
            channelUuid: $channel->uuid,
            receivedAt: now()->toIso8601String(),
            requestId: (string) $request->header('X-Request-Id', ''),
            secretToken: trim((string) $request->header('X-Telegram-Bot-Api-Secret-Token', '')),
            body: is_array($payload) ? $payload : [],
        );
    }

    public function updateId(): ?int
    {
        $updateId = $this->body['update_id'] ?? null;

        return is_int($updateId) ? $updateId : null;
    }

    public function updateKind(): ?string
    {
        foreach ([
            'message',
            'edited_message',
            'callback_query',
            'inline_query',
            'chosen_inline_result',
            'shipping_query',
            'pre_checkout_query',
            'poll',
            'poll_answer',
            'my_chat_member',
            'chat_member',
            'chat_join_request',
        ] as $key) {
            if (array_key_exists($key, $this->body)) {
                return $key;
            }
        }

        return null;
    }

    public function chatId(): ?string
    {
        $kind = $this->updateKind();

        if ($kind === null) {
            return null;
        }

        $node = $this->body[$kind];

        if (! is_array($node)) {
            return null;
        }

        if ($kind === 'callback_query') {
            $message = $node['message'] ?? null;
            $chat = is_array($message) ? ($message['chat'] ?? null) : null;
        } else {
            $chat = $node['chat'] ?? null;
        }

        $id = is_array($chat) ? ($chat['id'] ?? null) : null;

        if (is_int($id) || is_float($id)) {
            return (string) (int) $id;
        }

        return is_string($id) && trim($id) !== '' ? trim($id) : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function logContext(): array
    {
        $context = [
            'type' => $this->type,
            'channel_uuid' => $this->channelUuid,
            'update_id' => $this->updateId(),
            'update_kind' => $this->updateKind(),
            'chat_id' => $this->chatId(),
            'has_secret_token' => $this->secretToken !== '',
            'body_empty' => $this->body === [],
        ];

        if ($this->requestId !== '') {
            $context['request_id'] = $this->requestId;
        }

        if ($this->receivedAt !== '') {
            $context['received_at'] = $this->receivedAt;
        }

        return $context;
    }
}
