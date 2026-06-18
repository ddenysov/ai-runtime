<?php

namespace Tests\Unit\Telegram\Webhook;

use App\Telegram\Webhook\TelegramWebhookMessage;
use InvalidArgumentException;
use Tests\TestCase;

class TelegramWebhookMessageTest extends TestCase
{
    public function test_parses_sqs_envelope_json(): void
    {
        $json = <<<'JSON'
        {
          "version": 1,
          "type": "agent_channel",
          "channel_uuid": "550e8400-e29b-41d4-a716-446655440000",
          "received_at": "2026-06-18T12:00:00Z",
          "request_id": "req-123",
          "headers": {
            "x-telegram-bot-api-secret-token": "secret-value"
          },
          "body": {
            "update_id": 42,
            "message": {
              "text": "hello"
            }
          }
        }
        JSON;

        $message = TelegramWebhookMessage::fromJson($json);

        $this->assertSame(1, $message->version);
        $this->assertSame('agent_channel', $message->type);
        $this->assertSame('550e8400-e29b-41d4-a716-446655440000', $message->channelUuid);
        $this->assertSame('2026-06-18T12:00:00Z', $message->receivedAt);
        $this->assertSame('req-123', $message->requestId);
        $this->assertSame('secret-value', $message->secretToken);
        $this->assertSame(42, $message->updateId());
        $this->assertSame('hello', $message->body['message']['text']);
        $this->assertSame('message', $message->updateKind());
        $this->assertSame([
            'type' => 'agent_channel',
            'channel_uuid' => '550e8400-e29b-41d4-a716-446655440000',
            'update_id' => 42,
            'update_kind' => 'message',
            'chat_id' => null,
            'has_secret_token' => true,
            'body_empty' => false,
            'request_id' => 'req-123',
            'received_at' => '2026-06-18T12:00:00Z',
        ], $message->logContext());
    }

    public function test_log_context_includes_chat_id_for_message_updates(): void
    {
        $message = TelegramWebhookMessage::fromEnvelope([
            'version' => 1,
            'type' => 'agent_channel',
            'channel_uuid' => '550e8400-e29b-41d4-a716-446655440000',
            'body' => [
                'update_id' => 1,
                'message' => [
                    'chat' => ['id' => 12345],
                    'text' => 'hi',
                ],
            ],
        ]);

        $this->assertSame('12345', $message->chatId());
        $this->assertSame('12345', $message->logContext()['chat_id']);
    }

    public function test_rejects_invalid_json(): void
    {
        $this->expectException(InvalidArgumentException::class);

        TelegramWebhookMessage::fromJson('{not-json');
    }

    public function test_rejects_missing_channel_uuid(): void
    {
        $this->expectException(InvalidArgumentException::class);

        TelegramWebhookMessage::fromEnvelope([
            'version' => 1,
            'type' => 'agent_channel',
            'body' => [],
        ]);
    }
}
