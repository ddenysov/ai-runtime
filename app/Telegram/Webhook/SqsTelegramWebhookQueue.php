<?php

namespace App\Telegram\Webhook;

use Aws\Sqs\SqsClient;
use InvalidArgumentException;

final class SqsTelegramWebhookQueue
{
    public function __construct(
        private readonly SqsClient $client,
        private readonly string $queueUrl,
        private readonly int $waitTimeSeconds,
        private readonly int $maxMessages,
        private readonly int $visibilityTimeout,
    ) {}

    public static function fromConfig(): self
    {
        $queueName = config('telegram.sqs.queue');
        $prefix = config('telegram.sqs.prefix');
        $region = config('telegram.sqs.region');

        if (! is_string($queueName) || trim($queueName) === '') {
            throw new InvalidArgumentException('SQS_WEBHOOK_QUEUE is not configured.');
        }

        if (! is_string($prefix) || trim($prefix) === '') {
            throw new InvalidArgumentException('SQS_PREFIX is not configured.');
        }

        if (! is_string($region) || trim($region) === '') {
            throw new InvalidArgumentException('AWS_DEFAULT_REGION is not configured.');
        }

        $key = config('telegram.sqs.key');
        $secret = config('telegram.sqs.secret');

        if (! is_string($key) || trim($key) === '' || ! is_string($secret) || trim($secret) === '') {
            throw new InvalidArgumentException(
                'AWS credentials are not configured for the Telegram webhook consumer. '
                .'Set AWS_ACCESS_KEY_ID and AWS_SECRET_ACCESS_KEY once in .env (home-consumer IAM key). '
                .'Duplicate AWS_* entries later in the file override earlier values and leave credentials empty.',
            );
        }

        $client = new SqsClient([
            'version' => 'latest',
            'region' => $region,
            'credentials' => [
                'key' => $key,
                'secret' => $secret,
            ],
        ]);

        return new self(
            client: $client,
            queueUrl: rtrim($prefix, '/').'/'.ltrim($queueName, '/'),
            waitTimeSeconds: (int) config('telegram.sqs.wait_time_seconds', 20),
            maxMessages: (int) config('telegram.sqs.max_messages', 10),
            visibilityTimeout: (int) config('telegram.sqs.visibility_timeout', 120),
        );
    }

    /**
     * @return list<array{message_id: string, receipt_handle: string, body: string}>
     */
    public function receive(): array
    {
        $result = $this->client->receiveMessage([
            'QueueUrl' => $this->queueUrl,
            'MaxNumberOfMessages' => max(1, min(10, $this->maxMessages)),
            'WaitTimeSeconds' => max(0, min(20, $this->waitTimeSeconds)),
            'VisibilityTimeout' => max(0, $this->visibilityTimeout),
            'MessageAttributeNames' => ['All'],
        ]);

        $messages = [];

        foreach (($result['Messages'] ?? []) as $message) {
            if (! is_array($message)) {
                continue;
            }

            $messageId = $message['MessageId'] ?? null;
            $receiptHandle = $message['ReceiptHandle'] ?? null;
            $body = $message['Body'] ?? null;

            if (! is_string($messageId) || ! is_string($receiptHandle) || ! is_string($body)) {
                continue;
            }

            $messages[] = [
                'message_id' => $messageId,
                'receipt_handle' => $receiptHandle,
                'body' => $body,
            ];
        }

        return $messages;
    }

    public function delete(string $receiptHandle): void
    {
        $this->client->deleteMessage([
            'QueueUrl' => $this->queueUrl,
            'ReceiptHandle' => $receiptHandle,
        ]);
    }

    public function queueUrl(): string
    {
        return $this->queueUrl;
    }
}
