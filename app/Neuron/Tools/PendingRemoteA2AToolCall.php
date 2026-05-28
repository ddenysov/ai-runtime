<?php

namespace App\Neuron\Tools;

use RuntimeException;

class PendingRemoteA2AToolCall extends RuntimeException
{
    public function __construct(
        public readonly RemoteA2AToolInterrupt $interrupt,
    ) {
        parent::__construct($interrupt->getMessage());
    }
}
