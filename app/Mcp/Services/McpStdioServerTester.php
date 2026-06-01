<?php

namespace App\Mcp\Services;

use App\Mcp\Models\McpServer;
use Illuminate\Support\Facades\Log;
use Mcp\Client;
use Mcp\Client\Transport\StdioTransport;
use Psr\Log\LoggerInterface;
use Throwable;

final class McpStdioServerTester
{
    public function __construct(
        private McpStdioServerLaunchParamsResolver $launchParams,
    ) {}

    /**
     * @return array{ok: bool, message: string, tools_count?: int}
     */
    public function test(McpServer $server): array
    {
        $resolved = $this->launchParams->resolve($server);
        if (! $resolved['ok']) {
            Log::channel('mcp_stdio_test')->notice('MCP stdio test blocked', [
                'mcp_server_uuid' => $server->uuid,
                'mcp_server_name' => $server->name,
                'message' => $resolved['message'],
            ]);

            return [
                'ok' => false,
                'message' => $resolved['message'],
            ];
        }

        /** @var LoggerInterface $mcpLog */
        $mcpLog = Log::channel('mcp_stdio_test');

        $client = Client::builder()
            ->setClientInfo('AI Runtime MCP Tester', '1.0.0')
            ->setInitTimeout(seconds: 240)
            ->setRequestTimeout(240)
            ->setLogger($mcpLog)
            ->build();

        try {
            $client->connect(new StdioTransport(
                command: $resolved['command'],
                args: $resolved['args'],
                cwd: $resolved['cwd'],
                env: $resolved['procEnv'],
                logger: $mcpLog,
            ));

            $toolsResult = $client->listTools();
            $count = count($toolsResult->tools);

            $mcpLog->info('MCP stdio test succeeded', [
                'mcp_server_uuid' => $server->uuid,
                'mcp_server_name' => $server->name,
                'tools_count' => $count,
            ]);

            return [
                'ok' => true,
                'message' => 'Connected and listed tools successfully.',
                'tools_count' => $count,
            ];
        } catch (Throwable $e) {
            $mcpLog->warning('MCP stdio test failed', [
                'mcp_server_uuid' => $server->uuid,
                'mcp_server_name' => $server->name,
                'exception' => $e->getMessage(),
            ]);

            return [
                'ok' => false,
                'message' => $e->getMessage(),
            ];
        } finally {
            try {
                $client->disconnect();
            } catch (Throwable) {
                // ignore disconnect failures
            }
        }
    }
}
