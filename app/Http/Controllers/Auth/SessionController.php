<?php

namespace App\Http\Controllers\Auth;

use App\Gate\GateAuthCookieResponse;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class SessionController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return response()->json([
            'data' => $this->serializeUser($user),
            'csrf_token' => csrf_token(),
        ]);
    }

    /**
     * @throws ValidationException
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'login' => ['required', 'string'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        $login = trim((string) $validated['login']);
        $user = User::query()
            ->where('email', $login)
            ->orWhere('name', $login)
            ->first();

        if (! $user instanceof User || ! Auth::attempt([
            'email' => $user->email,
            'password' => $validated['password'],
        ], (bool) ($validated['remember'] ?? false))) {
            throw ValidationException::withMessages([
                'login' => 'The provided credentials are incorrect.',
            ]);
        }

        $request->session()->regenerate();

        return response()
            ->json([
                'data' => $this->serializeUser($request->user()),
                'csrf_token' => csrf_token(),
            ])
            ->withCookie(GateAuthCookieResponse::makeCookie());
    }

    public function destroy(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()
            ->json([
                'data' => ['ok' => true],
                'csrf_token' => csrf_token(),
            ])
            ->withCookie(GateAuthCookieResponse::forgetCookie())
            ->withoutCookie(config('session.cookie'));
    }

    /**
     * @return array{id: int, name: string, email: string}
     */
    private function serializeUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
        ];
    }
}
