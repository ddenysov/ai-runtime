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
}
