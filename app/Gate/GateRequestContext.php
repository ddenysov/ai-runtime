<?php

namespace App\Gate;

use Illuminate\Http\Request;

final class GateRequestContext
{
    /**
     * @param  array<string, string>  $cookies
     * @param  array<string, mixed>  $queryParams
     * @param  array<string, mixed>  $postParams
     * @param  array<string, string>  $headers
     */
    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly string $userAgent,
        private readonly array $cookies,
        private readonly array $queryParams = [],
        private readonly array $postParams = [],
        private readonly array $headers = [],
    ) {}

    /**
     * @param  array<string, mixed>  $server
     * @param  array<string, mixed>  $post
     */
    public static function fromServer(array $server, array $post = []): self
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

        $queryString = parse_url($uri, PHP_URL_QUERY);
        /** @var array<string, mixed> $queryParams */
        $queryParams = [];

        if (is_string($queryString) && $queryString !== '') {
            parse_str($queryString, $queryParams);
        }

        return new self(
            $method,
            $path,
            $userAgent,
            $cookies,
            $queryParams,
            $post,
            self::headersFromServer($server),
        );
    }

    public static function fromRequest(Request $request): self
    {
        /** @var array<string, mixed> $postParams */
        $postParams = $request->request->all();

        if ($postParams === [] && $request->isJson()) {
            $json = $request->json()->all();

            if (is_array($json)) {
                $postParams = $json;
            }
        }

        /** @var array<string, string> $headers */
        $headers = [];

        foreach ($request->headers->all() as $name => $values) {
            $headers[$name] = is_array($values) ? implode(', ', $values) : (string) $values;
        }

        return new self(
            strtoupper($request->getMethod()),
            '/'.ltrim($request->path(), '/'),
            $request->userAgent() ?? 'unknown',
            $request->cookies->all(),
            $request->query->all(),
            $postParams,
            $headers,
        );
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

    /**
     * @return array<string, mixed>
     */
    public function queryParams(): array
    {
        return $this->queryParams;
    }

    /**
     * @return array<string, mixed>
     */
    public function postParams(): array
    {
        return $this->postParams;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
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

    /**
     * @param  array<string, mixed>  $server
     * @return array<string, string>
     */
    private static function headersFromServer(array $server): array
    {
        /** @var array<string, string> $headers */
        $headers = [];

        foreach ($server as $key => $value) {
            if (! is_string($key) || ! is_string($value)) {
                continue;
            }

            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace('_', '-', substr($key, 5));
                $headers[$name] = $value;

                continue;
            }

            if ($key === 'CONTENT_TYPE') {
                $headers['Content-Type'] = $value;

                continue;
            }

            if ($key === 'CONTENT_LENGTH') {
                $headers['Content-Length'] = $value;
            }
        }

        return $headers;
    }
}
