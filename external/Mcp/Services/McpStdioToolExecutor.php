<?php

namespace App\Mcp\Services;

use App\Mcp\Models\McpServer;
use Illuminate\Support\Facades\Log;
use JsonException;
use Mcp\Client;
use Mcp\Client\Transport\StdioTransport;
use Throwable;

final class McpStdioToolExecutor
{
    private const MCP_AGENT_LOG_CHANNEL = 'mcp_agent';

    private const MAX_LOGGED_JSON_CHARS = 65536;

    public function __construct(
        private McpStdioServerLaunchParamsResolver $launchParams,
    ) {}

    /**
     * Connects to the MCP server, invokes the tool, disconnects, and returns a JSON string
     * suitable for feeding back to the LLM (including MCP-level and transport errors).
     *
     * @param  array<string, mixed>  $arguments
     */
    public function execute(McpServer $server, string $toolName, array $arguments): string
    {
        $resolved = $this->launchParams->resolve($server);
        if (! $resolved['ok']) {
            Log::channel(self::MCP_AGENT_LOG_CHANNEL)->debug('MCP agent tool response (launch blocked)', [
                'mcp_server_uuid' => $server->uuid,
                'mcp_server_name' => $server->name,
                'tool' => $toolName,
                'arguments' => $arguments,
                'ok' => false,
                'message' => $resolved['message'],
            ]);

            return $this->encodeResultPayload([
                'ok' => false,
                'message' => $resolved['message'],
            ]);
        }

        $client = Client::builder()
            ->setClientInfo('AI Admin Agent MCP', '1.0.0')
            ->setInitTimeout(30)
            ->setRequestTimeout(120)
            ->build();

        try {
            $transport = new StdioTransport(
                command: $resolved['command'],
                args: $resolved['args'],
                cwd: $resolved['cwd'],
                env: $resolved['procEnv'],
            );
            $client->connect($transport);

            Log::channel(self::MCP_AGENT_LOG_CHANNEL)->debug('MCP agent tool request', [
                'mcp_server_uuid' => $server->uuid,
                'mcp_server_name' => $server->name,
                'tool' => $toolName,
                'arguments' => $arguments,
            ]);

            $result = $client->callTool($toolName, $arguments);

            $encoded = $this->encodeResultPayload($result->jsonSerialize());

            Log::channel(self::MCP_AGENT_LOG_CHANNEL)->debug('MCP agent tool response', [
                'mcp_server_uuid' => $server->uuid,
                'mcp_server_name' => $server->name,
                'tool' => $toolName,
                'result_json' => $this->truncateForLog($encoded),
            ]);

            return $encoded;
        } catch (Throwable $e) {
            Log::channel(self::MCP_AGENT_LOG_CHANNEL)->debug('MCP agent tool response (exception)', [
                'mcp_server_uuid' => $server->uuid,
                'mcp_server_name' => $server->name,
                'tool' => $toolName,
                'arguments' => $arguments,
                'ok' => false,
                'message' => $e->getMessage(),
            ]);

            return $this->encodeResultPayload([
                'ok' => false,
                'message' => $e->getMessage(),
            ]);
        } finally {
            try {
                $client->disconnect();
            } catch (Throwable) {
                // ignore
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function encodeResultPayload(array $payload): string
    {
        try {
            return json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE,
            );
        } catch (JsonException) {
            return '{"ok":false,"message":"Failed to encode MCP tool result as JSON."}';
        }
    }

    private function truncateForLog(string $json): string
    {
        if (strlen($json) <= self::MAX_LOGGED_JSON_CHARS) {
            return $json;
        }

        return substr($json, 0, self::MAX_LOGGED_JSON_CHARS).'… [truncated for log]';
    }
}
