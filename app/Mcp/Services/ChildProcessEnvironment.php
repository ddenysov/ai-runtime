<?php

namespace App\Mcp\Services;

/**
 * Builds a full environment map for proc_open when the child must inherit the
 * current process environment plus MCP-specific variables.
 */
final class ChildProcessEnvironment
{
    /**
     * @param  array<string, string>  $extra
     * @return array<string, string>|null null means inherit the parent environment unchanged
     */
    public function mergeWithCurrent(array $extra): ?array
    {
        if ($extra === []) {
            return null;
        }

        $base = $this->currentStringEnvironment();
        $base = $this->augmentFromRealProcessEnvironment($base);

        return array_merge($base, $extra);
    }

    /**
     * @param  array<string, string>  $base
     * @return array<string, string>
     */
    private function augmentFromRealProcessEnvironment(array $base): array
    {
        foreach ($this->processEnvironmentPassthroughKeys() as $key) {
            if (isset($base[$key])) {
                continue;
            }

            $value = getenv($key);
            if (is_string($value) && $value !== '') {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    /**
     * @return list<string>
     */
    private function processEnvironmentPassthroughKeys(): array
    {
        return [
            'HOME',
            'PATH',
            'LANG',
            'LC_ALL',
            'TMPDIR',
            'UV_CACHE_DIR',
            'XDG_CACHE_HOME',
            'XDG_CONFIG_HOME',
            'XDG_DATA_HOME',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function currentStringEnvironment(): array
    {
        $base = [];

        foreach ($_ENV as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $base[$key] = $value;
            }
        }

        foreach ($_SERVER as $key => $value) {
            if (! is_string($key) || ! is_string($value) || str_starts_with($key, 'HTTP_')) {
                continue;
            }

            $base[$key] ??= $value;
        }

        return $base;
    }
}
