<?php

namespace App\A2A;

final class A2AState
{
    public const SUBMITTED = 'SUBMITTED';

    public const WORKING = 'WORKING';

    public const COMPLETED = 'COMPLETED';

    public const FAILED = 'FAILED';

    public const CANCELED = 'CANCELED';

    public const REJECTED = 'REJECTED';

    public static function isTerminal(string $state): bool
    {
        return in_array($state, [
            self::COMPLETED,
            self::FAILED,
            self::CANCELED,
            self::REJECTED,
        ], true);
    }
}
