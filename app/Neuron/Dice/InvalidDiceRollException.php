<?php

namespace App\Neuron\Dice;

use InvalidArgumentException;

class InvalidDiceRollException extends InvalidArgumentException
{
    public function __construct(
        string $message,
        public readonly ?string $reason = null,
    ) {
        parent::__construct($message);
    }
}
