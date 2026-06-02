<?php

namespace App\Channels\Services;

use App\A2A\SendMessageAction;
use App\A2A\TaskPayloadFactory;
use App\Channels\Models\AgentChannel;
use App\Channels\Models\AgentChannelThread;
use App\Models\Agent;
use Illuminate\Support\Str;
use Telegram\Bot\Objects\Message;
use Telegram\Bot\Objects\Update;

final class TelegramIncomingMessageHandler
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
}
