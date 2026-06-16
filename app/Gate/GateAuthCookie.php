<?php

namespace App\Gate;

final class GateAuthCookie
{
    public const NAME = 'gate_auth';

    public const VALUE = '1';

    /**
     * @param  array<string, string>  $cookies
     */
    public static function isPresent(array $cookies): bool
    {
        return isset($cookies[self::NAME]) && $cookies[self::NAME] !== '';
    }
}
