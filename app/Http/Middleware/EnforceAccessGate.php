<?php

namespace App\Http\Middleware;

use App\Gate\GateFrontDoor;
use App\Gate\Nginx404Response;
use App\Gate\TelegramGateClient;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnforceAccessGate
{
    public function handle(Request $request, Closure $next): Response
    {
        $gate = GateFrontDoor::fromDefaults(
            storagePath: (string) config('gate.storage_path'),
            envEnabled: (bool) config('gate.enabled'),
            openSeconds: (int) config('gate.open_seconds', 120),
            notificationCooldownSeconds: (int) config('gate.notification_cooldown_seconds', 300),
            gatekeeperWebhookPath: (string) config('gate.webhook_path'),
        );

        if ($gate->shouldBootstrapApplication($this->serverVariables($request))) {
            return $next($request);
        }

        $this->notifyIfNeeded($gate, $request);

        return Nginx404Response::toResponse();
    }

    /**
     * @return array<string, mixed>
     */
    private function serverVariables(Request $request): array
    {
        return [
            'REQUEST_URI' => $request->getRequestUri(),
            'REQUEST_METHOD' => $request->getMethod(),
            'HTTP_USER_AGENT' => $request->userAgent() ?? 'unknown',
            'HTTP_COOKIE' => implode('; ', array_map(
                static fn (string $name, string $value): string => $name.'='.$value,
                array_keys($request->cookies->all()),
                array_values($request->cookies->all()),
            )),
        ];
    }

    private function notifyIfNeeded(GateFrontDoor $gate, Request $request): void
    {
        $config = $gate->config();
        $state = $gate->state();

        if (! $config->isActive() || ! $state->shouldNotify((int) config('gate.notification_cooldown_seconds', 300))) {
            return;
        }

        $client = new TelegramGateClient($config->botToken());
        $client->sendVisitAlert(
            $config->telegramChatId(),
            implode("\n", [
                'Site access attempt',
                'Method: '.$request->getMethod(),
                'Path: '.$request->path(),
                'User-Agent: '.($request->userAgent() ?? 'unknown'),
            ]),
        );
        $state->markNotified();
    }
}
