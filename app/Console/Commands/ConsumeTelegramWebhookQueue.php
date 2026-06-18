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
    private const CONFIG_RETRY_SECONDS = 30;

    protected $signature = 'telegram:consume-webhooks
                            {--once : Process one receive batch and exit}
                            {--sleep=1 : Seconds to sleep when the queue is empty}';

    protected $description = 'Long-poll the Telegram webhook SQS queue and process updates';

    public function handle(TelegramWebhookIngress $ingress): int
    {
        $once = (bool) $this->option('once');
        $sleep = max(0, (int) $this->option('sleep'));

        $queue = $this->resolveQueue($once);

        if ($queue === null) {
            return SymfonyCommand::FAILURE;
        }

        $this->info('Consuming Telegram webhooks from '.$queue->queueUrl());

        do {
            try {
                if ($this->output->isVerbose()) {
                    $this->line('Polling SQS...');
                }

                $messages = $queue->receive();
            } catch (Throwable $exception) {
                Log::error('Telegram webhook SQS receive failed.', [
                    'exception' => $exception,
                ]);
                $this->error('SQS receive failed: '.$exception->getMessage());

                if ($once) {
                    return SymfonyCommand::FAILURE;
                }

                sleep($sleep);

                continue;
            }

            if ($messages === []) {
                if ($once) {
                    break;
                }

                continue;
            }

            foreach ($messages as $message) {
                try {
                    $this->processMessage($ingress, $queue, $message);
                } catch (Throwable $exception) {
                    Log::error('Telegram webhook message processing crashed.', [
                        'message_id' => $message['message_id'],
                        'exception' => $exception,
                    ]);
                    $this->error('Message processing failed: '.$exception->getMessage());
                }
            }
        } while (! $once);

        return SymfonyCommand::SUCCESS;
    }

    private function resolveQueue(bool $once): ?SqsTelegramWebhookQueue
    {
        while (true) {
            try {
                return SqsTelegramWebhookQueue::fromConfig();
            } catch (InvalidArgumentException $exception) {
                $this->error($exception->getMessage());

                if ($once) {
                    return null;
                }

                Log::warning('Telegram webhook consumer waiting for SQS configuration.', [
                    'retry_in_seconds' => self::CONFIG_RETRY_SECONDS,
                ]);

                sleep(self::CONFIG_RETRY_SECONDS);
            }
        }
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
            if ($this->output->isVerbose()) {
                $this->line(sprintf(
                    'Processed webhook %s (update %s)',
                    $webhookMessage->requestId !== '' ? $webhookMessage->requestId : $message['message_id'],
                    $webhookMessage->updateId() ?? 'n/a',
                ));
            }

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
