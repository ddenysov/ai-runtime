<?php

namespace App\Channels\Services;

use App\Channels\Contracts\ChannelDeliveryDestinationResolver;
use App\Channels\Models\AgentChannel;
use App\Models\Agent;

final class AgentChannelDeliveryResolver
{
    /**
     * @param  list<ChannelDeliveryDestinationResolver>  $resolvers
     */
    public function __construct(
        private readonly array $resolvers,
    ) {}

    public function resolveForAgent(Agent $agent): ?ChannelDeliveryDestination
    {
        $channels = AgentChannel::query()
            ->where('agent_id', $agent->id)
            ->where('enabled', true)
            ->orderBy('id')
            ->get();

        foreach ($channels as $channel) {
            foreach ($this->resolvers as $resolver) {
                if (! $resolver->supports($channel->type)) {
                    continue;
                }

                $destination = $resolver->resolve($channel);

                if ($destination instanceof ChannelDeliveryDestination) {
                    return $destination;
                }
            }
        }

        return null;
    }
}
