<?php

namespace App\Channels\Contracts;

use App\Channels\Models\AgentChannel;
use App\Channels\Services\ChannelDeliveryDestination;

interface ChannelDeliveryDestinationResolver
{
    public function supports(string $channelType): bool;

    public function resolve(AgentChannel $channel): ?ChannelDeliveryDestination;
}
