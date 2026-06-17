<?php

namespace App\Gate;

final class GateFrontDoor
{
    public function __construct(
        private readonly GateConfig $config,
        private readonly GateState $state,
        private readonly int $openSeconds,
        private readonly int $notificationCooldownSeconds,
        private readonly string $gatekeeperWebhookPath,
    ) {}

    public static function fromDefaults(
        string $storagePath,
        bool $envEnabled,
        int $openSeconds = 120,
        int $notificationCooldownSeconds = 300,
        string $gatekeeperWebhookPath = '/api/integrations/gatekeeper/telegram/webhook',
    ): self {
        return new self(
            config: GateConfig::load($storagePath, $envEnabled),
            state: GateState::make($storagePath),
            openSeconds: $openSeconds,
            notificationCooldownSeconds: $notificationCooldownSeconds,
            gatekeeperWebhookPath: $gatekeeperWebhookPath,
        );
    }

    /**
     * @param  array<string, mixed>  $server
     */
    public function shouldBootstrapApplication(array $server): bool
    {
        if (! $this->config->isActive()) {
            return true;
        }

        $request = GateRequestContext::fromServer($server);

        if ($request->isWebhookPath($this->gatekeeperWebhookPath)) {
            return true;
        }

        if ($this->state->isOpen()) {
            return true;
        }

        if ($request->hasGateAuthCookie()) {
            return true;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $server
     * @param  array<string, mixed>  $post
     */
    public function handleBlockedRequest(array $server, array $post = []): never
    {
        $request = GateRequestContext::fromServer($server, $post);

        if ($this->config->isActive()
            && $this->state->shouldNotify($this->notificationCooldownSeconds)
        ) {
            $client = new TelegramGateClient($this->config->botToken());
            $client->sendVisitAlert(
                $this->config->telegramChatId(),
                GateVisitAlertMessage::fromContext($request),
            );
            $this->state->markNotified();
        }

        Nginx404Response::send();
    }

    public function config(): GateConfig
    {
        return $this->config;
    }

    public function state(): GateState
    {
        return $this->state;
    }

    public function openSeconds(): int
    {
        return $this->openSeconds;
    }
}
