<?php

namespace App\Neuron\Dice;

enum RollKind: string
{
    case Attack = 'attack';
    case Check = 'check';
    case Save = 'save';

    public static function tryFromString(?string $value): ?self
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return self::tryFrom(strtolower(trim($value)));
    }
}
