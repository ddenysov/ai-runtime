<?php

namespace App\Mcp\Services;

use App\Mcp\Models\McpServer;

final class McpStdioServerLaunchParamsResolver
{
    public function __construct(
        private ChildProcessEnvironment $childProcessEnvironment,
    ) {}

    /**
     * @return array{
     *     ok: true,
     *     command: string,
     *     args: array<int, string>,
     *     cwd: ?string,
     *     procEnv: array<string, string>|null
     * }|array{ok: false, message: string}
     */
    public function resolve(McpServer $server): array
    {
        if (! $server->enabled) {
            return [
                'ok' => false,
                'message' => 'MCP server is disabled.',
            ];
        }

        if ($server->transport !== 'stdio') {
            return [
                'ok' => false,
                'message' => 'Only stdio transport is supported.',
            ];
        }

        /** @var array<int, string> $args */
        $args = $server->args ?? [];
        foreach ($args as $index => $arg) {
            if (! is_string($arg)) {
                return [
                    'ok' => false,
                    'message' => 'All args entries must be strings (invalid index '.$index.').',
                ];
            }
        }

        /** @var array<string, string> $env */
        $env = [];
        foreach (($server->env ?? []) as $key => $value) {
            if (! is_string($key) || ! is_string($value)) {
                return [
                    'ok' => false,
                    'message' => 'Environment variables must be string keys and string values.',
                ];
            }

            $env[$key] = $value;
        }

        return [
            'ok' => true,
            'command' => $server->command,
            'args' => $args,
            'cwd' => $server->cwd,
            'procEnv' => $this->childProcessEnvironment->mergeWithCurrent($env),
        ];
    }
}
