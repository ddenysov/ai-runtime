<?php

namespace App\Channels\Services;

use App\Channels\Contracts\ChannelDeliveryDestinationResolver;
use App\Channels\Models\AgentChannel;
use App\Channels\Models\AgentChannelThread;

final class TelegramChannelDeliveryDestinationResolver implements ChannelDeliveryDestinationResolver
{
    public function supports(string $channelType): bool
    {
        return $channelType === 'telegram';
    }

    public function resolve(AgentChannel $channel): ?ChannelDeliveryDestination
    {
        $thread = AgentChannelThread::query()
            ->where('agent_channel_id', $channel->id)
            ->orderBy('id')
            ->first();

        if (! $thread instanceof AgentChannelThread) {
            return null;
        }

        $chatId = trim($thread->external_chat_id);

        if ($chatId === '') {
            return null;
        }

        return new ChannelDeliveryDestination(
            deliveryChannel: [
                'type' => 'telegram',
                'agent_channel_uuid' => $channel->uuid,
                'external_chat_id' => $chatId,
            ],
            contextId: $thread->context_id,
        );
    }
}
