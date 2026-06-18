<?php

namespace App\Channels\Services;

use App\A2A\A2AState;
use App\A2A\SendMessageAction;
use App\A2A\TaskPayloadFactory;
use App\Channels\Models\AgentChannel;
use App\Channels\Models\AgentChannelThread;
use App\Models\A2ATask;
use App\Models\Agent;
use App\Models\AgentChatMessage;
use App\Models\AgentRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Telegram\Bot\Objects\Message;
use Telegram\Bot\Objects\Update;

class TelegramIncomingMessageHandler
{
    public function __construct(
        private readonly SendMessageAction $messages,
        private readonly TaskPayloadFactory $payloads,
        private readonly TelegramOutboundMessenger $telegram,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(AgentChannel $channel, array $payload): void
    {
        if ($payload === []) {
            return;
        }

        $agent = Agent::query()->find($channel->agent_id);

        if ($agent === null || ! $agent->is_active) {
            return;
        }

        $update = new Update($payload);

        if ($update->has('callback_query')) {
            $this->handleRetryCallback($channel, $agent, $update);

            return;
        }

        if (! $update->has('message')) {
            return;
        }

        /** @var Message $message */
        $message = $update->message;
        $text = $message->get('text');

        if (! is_string($text)) {
            return;
        }

        $text = trim($text);

        if ($text === '') {
            return;
        }

        $chat = $message->get('chat');
        $chatId = $chat !== null && $chat->has('id') ? (string) $chat->get('id') : null;

        if ($chatId === null || $chatId === '') {
            return;
        }

        $thread = AgentChannelThread::query()->firstOrCreate(
            [
                'agent_channel_id' => $channel->id,
                'external_chat_id' => $chatId,
            ],
            [
                'context_id' => (string) Str::uuid(),
            ],
        );

        if (TelegramChatSession::isNewChatRequest($text)) {
            $thread->resetContext();
            $this->telegram->sendChatNotice($channel, $chatId, TelegramChatSession::NEW_CHAT_ACK_MESSAGE);

            return;
        }

        $this->messages->handle(
            $agent->slug,
            $this->payloads->userMessage($text),
            metadata: [
                'contextId' => $thread->context_id,
                'source' => 'telegram',
                'delivery_channel' => [
                    'type' => 'telegram',
                    'agent_channel_uuid' => $channel->uuid,
                    'external_chat_id' => $chatId,
                ],
                'telegram_update_id' => $update->get('update_id'),
            ],
        );
    }

    private function handleRetryCallback(AgentChannel $channel, Agent $agent, Update $update): void
    {
        $callbackQuery = $update->get('callback_query');
        $callbackQueryId = $this->telegramObjectValue($callbackQuery, 'id');
        $data = $this->telegramObjectValue($callbackQuery, 'data');

        if (! is_string($data) || ! str_starts_with($data, TelegramOutboundMessenger::RETRY_CALLBACK_PREFIX)) {
            return;
        }

        $taskId = trim(substr($data, strlen(TelegramOutboundMessenger::RETRY_CALLBACK_PREFIX)));

        if ($taskId === '') {
            $this->answerRetryCallback($channel, $callbackQueryId, 'This request can no longer be retried.');

            return;
        }

        $message = $this->telegramObjectValue($callbackQuery, 'message');
        $chat = $this->telegramObjectValue($message, 'chat');
        $chatId = $this->telegramObjectValue($chat, 'id');
        $chatId = is_int($chatId) || is_string($chatId) ? (string) $chatId : null;

        if ($chatId === null || $chatId === '') {
            $this->answerRetryCallback($channel, $callbackQueryId, 'This request can no longer be retried.');

            return;
        }

        $thread = AgentChannelThread::query()
            ->where('agent_channel_id', $channel->id)
            ->where('external_chat_id', $chatId)
            ->first();

        if (! $thread instanceof AgentChannelThread) {
            $this->answerRetryCallback($channel, $callbackQueryId, 'This chat session is no longer available.');

            return;
        }

        $record = A2ATask::query()->find($taskId);
        $task = $record instanceof A2ATask ? $record->payload : null;

        if (! $record instanceof A2ATask || ! is_array($task)) {
            $this->answerRetryCallback($channel, $callbackQueryId, 'This request can no longer be retried.');

            return;
        }

        if (! $this->isRetryableTaskForChat($record, $task, $agent, $channel, $thread, $chatId)) {
            $this->answerRetryCallback($channel, $callbackQueryId, 'This retry is no longer available.');

            return;
        }

        $text = $this->firstUserTextFromTask($task);

        if ($text === null) {
            $this->answerRetryCallback($channel, $callbackQueryId, 'The original request text is no longer available.');

            return;
        }

        $runId = $task['metadata']['agent_run_id'] ?? null;
        $latestRun = $this->latestRunForContext($agent, $thread->context_id);

        if (! is_string($runId) || ! $latestRun instanceof AgentRun || $latestRun->id !== $runId || $latestRun->state !== 'failed') {
            $this->answerRetryCallback($channel, $callbackQueryId, 'Only the latest failed request can be retried.');

            return;
        }

        if (! $this->replaceFailedLastUserMessage($agent, $thread->context_id, $text)) {
            $this->answerRetryCallback($channel, $callbackQueryId, 'This request can no longer be retried.');

            return;
        }

        $this->messages->handle(
            $agent->slug,
            $this->payloads->userMessage($text),
            metadata: [
                'contextId' => $thread->context_id,
                'source' => 'telegram',
                'delivery_channel' => [
                    'type' => 'telegram',
                    'agent_channel_uuid' => $channel->uuid,
                    'external_chat_id' => $chatId,
                ],
                'telegram_retry_of_task_id' => $taskId,
                'telegram_retry_of_run_id' => $runId,
                'telegram_update_id' => $update->get('update_id'),
            ],
        );

        $this->answerRetryCallback($channel, $callbackQueryId, 'Retrying...');
        $this->telegram->sendChatNotice($channel, $chatId, 'Retrying the last request.');
    }

    private function answerRetryCallback(AgentChannel $channel, mixed $callbackQueryId, string $text): void
    {
        if (! is_string($callbackQueryId) || $callbackQueryId === '') {
            return;
        }

        $this->telegram->answerCallbackQuery($channel, $callbackQueryId, $text);
    }

    /**
     * @param  array<string, mixed>  $task
     */
    private function isRetryableTaskForChat(
        A2ATask $record,
        array $task,
        Agent $agent,
        AgentChannel $channel,
        AgentChannelThread $thread,
        string $chatId,
    ): bool {
        $state = $record->state;

        if (! in_array($state, [A2AState::FAILED, A2AState::REJECTED], true)) {
            return false;
        }

        if (($task['metadata']['agent_slug'] ?? null) !== $agent->slug) {
            return false;
        }

        if (($task['contextId'] ?? null) !== $thread->context_id) {
            return false;
        }

        $delivery = $task['metadata']['delivery_channel'] ?? null;

        if (! is_array($delivery)) {
            return false;
        }

        return ($delivery['type'] ?? null) === 'telegram'
            && ($delivery['agent_channel_uuid'] ?? null) === $channel->uuid
            && (string) ($delivery['external_chat_id'] ?? '') === $chatId;
    }

    /**
     * @param  array<string, mixed>  $task
     */
    private function firstUserTextFromTask(array $task): ?string
    {
        foreach (($task['history'] ?? []) as $message) {
            if (! is_array($message) || ($message['role'] ?? null) !== 'ROLE_USER') {
                continue;
            }

            $chunks = [];

            foreach (($message['parts'] ?? []) as $part) {
                if (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                    $chunks[] = $part['text'];
                }
            }

            $text = trim(implode("\n", $chunks));

            if ($text !== '') {
                return $text;
            }
        }

        return null;
    }

    private function latestRunForContext(Agent $agent, string $contextId): ?AgentRun
    {
        return AgentRun::query()
            ->where('agent_slug', $agent->slug)
            ->where('input->context_id', $contextId)
            ->latest()
            ->first();
    }

    private function replaceFailedLastUserMessage(Agent $agent, string $contextId, string $message): bool
    {
        $latestMessage = AgentChatMessage::query()
            ->where('thread_id', "{$agent->slug}:{$contextId}")
            ->latest('id')
            ->first();

        if ($latestMessage instanceof AgentChatMessage && $latestMessage->role !== 'user') {
            return false;
        }

        DB::transaction(function () use ($agent, $contextId, $latestMessage, $message): void {
            if ($latestMessage instanceof AgentChatMessage) {
                $latestMessage->delete();
            }

            AgentChatMessage::query()->create([
                'thread_id' => "{$agent->slug}:{$contextId}",
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'text',
                        'content' => $message,
                        'meta' => [],
                    ],
                ],
                'meta' => [
                    '__id' => 'msg_'.Str::uuid()->toString(),
                ],
            ]);
        });

        return true;
    }

    private function telegramObjectValue(mixed $object, string $key): mixed
    {
        if (is_object($object) && method_exists($object, 'get')) {
            return $object->get($key);
        }

        if (is_array($object)) {
            return $object[$key] ?? null;
        }

        return null;
    }
}
