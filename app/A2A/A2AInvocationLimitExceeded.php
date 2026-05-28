<?php

namespace App\A2A;

use RuntimeException;

class A2AInvocationLimitExceeded extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly array $details = [],
    ) {
        parent::__construct("A2A invocation limit exceeded: {$reason}");
    }
}
