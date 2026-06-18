<?php

namespace App\Console\Commands;

use App\Telegram\Webhook\SqsTelegramWebhookQueue;
use App\Telegram\Webhook\TelegramWebhookIngress;
use App\Telegram\Webhook\TelegramWebhookMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Throwable;

class ConsumeTelegramWebhookQueue extends Command
{
    protected $signature = 'telegram:consume-webhooks
                            {--once : Process one receive batch and exit}
                            {--sleep=1 : Seconds to sleep when the queue is empty}';

    protected $description = 'Long-poll the Telegram webhook SQS queue and process updates';

    public function handle(TelegramWebhookIngress $ingress): int
    {
        try {
            $queue = SqsTelegramWebhookQueue::fromConfig();
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return SymfonyCommand::FAILURE;
        }

        $this->info('Consuming Telegram webhooks from '.$queue->queueUrl());

        $once = (bool) $this->option('once');
        $sleep = max(0, (int) $this->option('sleep'));

        do {
            $messages = $queue->receive();

            if ($messages === []) {
                if ($once) {
                    break;
                }

                sleep($sleep);

                continue;
            }

            foreach ($messages as $message) {
                $this->processMessage($ingress, $queue, $message);
            }
        } while (! $once);

        return SymfonyCommand::SUCCESS;
    }

    /**
     * @param  array{message_id: string, receipt_handle: string, body: string}  $message
     */
    private function processMessage(
        TelegramWebhookIngress $ingress,
        SqsTelegramWebhookQueue $queue,
        array $message,
    ): void {
        try {
            $webhookMessage = TelegramWebhookMessage::fromJson($message['body']);
        } catch (InvalidArgumentException $exception) {
            Log::warning('Telegram webhook skipped: malformed SQS envelope.', [
                'message_id' => $message['message_id'],
                'exception' => $exception,
            ]);

            $queue->delete($message['receipt_handle']);

            return;
        }

        $result = $ingress->handleMessage($webhookMessage);

        if ($result->shouldDeleteFromQueue()) {
            try {
                $queue->delete($message['receipt_handle']);
            } catch (Throwable $exception) {
                Log::error('Failed to delete Telegram webhook SQS message.', [
                    'message_id' => $message['message_id'],
                    'exception' => $exception,
                ]);
            }

            return;
        }

        Log::warning('Telegram webhook left on queue for retry.', [
            'message_id' => $message['message_id'],
            'channel_uuid' => $webhookMessage->channelUuid,
            'request_id' => $webhookMessage->requestId,
        ]);
    }
}
