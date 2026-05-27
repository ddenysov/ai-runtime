<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateA2A
{
    public function handle(Request $request, Closure $next): Response
    {
        $configuredToken = config('runtime-agents.a2a_token');

        if (blank($configuredToken) && app()->environment(['local', 'testing'])) {
            return $next($request);
        }

        $incomingToken = $request->bearerToken();

        if (! is_string($incomingToken) || ! hash_equals((string) $configuredToken, $incomingToken)) {
            return response()->json(['message' => 'Unauthenticated.'], Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }
}
