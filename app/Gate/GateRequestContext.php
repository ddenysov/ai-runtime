<?php

namespace App\Gate;

final class GateRequestContext
{
    /**
     * @param  array<string, string>  $cookies
     */
    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly string $userAgent,
        private readonly array $cookies,
    ) {}

    /**
     * @param  array<string, mixed>  $server
     */
    public static function fromServer(array $server): self
    {
        $uri = isset($server['REQUEST_URI']) && is_string($server['REQUEST_URI'])
            ? $server['REQUEST_URI']
            : '/';
        $path = (string) parse_url($uri, PHP_URL_PATH);

        if ($path === '') {
            $path = '/';
        }

        $method = isset($server['REQUEST_METHOD']) && is_string($server['REQUEST_METHOD'])
            ? strtoupper($server['REQUEST_METHOD'])
            : 'GET';

        $userAgent = isset($server['HTTP_USER_AGENT']) && is_string($server['HTTP_USER_AGENT'])
            ? $server['HTTP_USER_AGENT']
            : 'unknown';

        /** @var array<string, string> $cookies */
        $cookies = [];

        if (isset($server['HTTP_COOKIE']) && is_string($server['HTTP_COOKIE']) && $server['HTTP_COOKIE'] !== '') {
            foreach (explode(';', $server['HTTP_COOKIE']) as $pair) {
                $parts = explode('=', trim($pair), 2);

                if (count($parts) === 2 && $parts[0] !== '') {
                    $cookies[$parts[0]] = $parts[1];
                }
            }
        }

        return new self($method, $path, $userAgent, $cookies);
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function userAgent(): string
    {
        return $this->userAgent;
    }

    public function hasGateAuthCookie(): bool
    {
        return GateAuthCookie::isPresent($this->cookies);
    }

    public function isWebhookPath(string $gatekeeperWebhookPath): bool
    {
        if ($this->path === $gatekeeperWebhookPath) {
            return true;
        }

        return (bool) preg_match('#^/api/integrations/telegram/webhooks/[^/]+$#', $this->path);
    }
}
