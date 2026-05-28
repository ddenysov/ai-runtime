<?php

namespace App\A2A;

enum A2AState: string
{
    case SUBMITTED = 'SUBMITTED';
    case WORKING = 'WORKING';
    case COMPLETED = 'COMPLETED';
    case FAILED = 'FAILED';
    case CANCELED = 'CANCELED';
    case REJECTED = 'REJECTED';

    public function terminal(): bool
    {
        return match ($this) {
            self::COMPLETED, self::FAILED, self::CANCELED, self::REJECTED => true,
            default => false,
        };
    }

    public static function isTerminal(self|string $state): bool
    {
        return ($state instanceof self ? $state : self::from($state))->terminal();
    }
}
