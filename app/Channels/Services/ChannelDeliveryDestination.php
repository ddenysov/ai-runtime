<?php

namespace App\Channels\Services;

final class ChannelDeliveryDestination
{
    /**
     * @param  array<string, mixed>  $deliveryChannel
     */
    public function __construct(
        public readonly array $deliveryChannel,
        public readonly ?string $contextId = null,
    ) {}
}
