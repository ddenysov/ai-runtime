<?php

namespace App\Gate;

use Symfony\Component\HttpFoundation\Cookie;

final class GateAuthCookieResponse
{
    public static function makeCookie(): Cookie
    {
        $lifetime = (int) config('session.lifetime', 120);

        return cookie(
            GateAuthCookie::NAME,
            GateAuthCookie::VALUE,
            $lifetime,
            config('session.path'),
            config('session.domain'),
            (bool) config('session.secure'),
            true,
            false,
            config('session.same_site'),
        );
    }

    public static function forgetCookie(): Cookie
    {
        return cookie()->forget(
            GateAuthCookie::NAME,
            config('session.path'),
            config('session.domain'),
        );
    }
}
