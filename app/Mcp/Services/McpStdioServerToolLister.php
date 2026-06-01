<?php

namespace App\Mcp\Services;

use App\Mcp\Models\McpServer;
use Illuminate\Support\Facades\Log;
use Mcp\Client;
use Mcp\Client\Transport\StdioTransport;
use Psr\Log\LoggerInterface;
use Throwable;

final class McpStdioServerToolLister
{
    public function __construct(
        private McpStdioServerLaunchParamsResolver $launchParams,
    ) {}

    /**
     * @return array{
     *     ok: true,
     *     message: string,
     *     tools: list<array{name: string, title: ?string, description: ?string, input_schema: array<string, mixed>}>
     * }|array{ok: false, message: string}
     */
    public function listTools(McpServer $server): array
    {
        $resolved = $this->launchParams->resolve($server);
        if (! $resolved['ok']) {
            Log::channel('mcp_stdio_test')->notice('MCP stdio listTools blocked', [
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
            ->setClientInfo('AI Runtime MCP Tool Lister', '1.0.0')
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

            $tools = [];
            $cursor = null;

            do {
                $page = $client->listTools($cursor);
                foreach ($page->tools as $tool) {
                    $tools[] = [
                        'name' => $tool->name,
                        'title' => $tool->title,
                        'description' => $tool->description,
                        'input_schema' => $tool->inputSchema,
                    ];
                }

                $cursor = $page->nextCursor;
            } while ($cursor !== null);

            $mcpLog->info('MCP stdio listTools succeeded', [
                'mcp_server_uuid' => $server->uuid,
                'mcp_server_name' => $server->name,
                'tools_count' => count($tools),
            ]);

            return [
                'ok' => true,
                'message' => 'Listed tools successfully.',
                'tools' => $tools,
            ];
        } catch (Throwable $e) {
            $mcpLog->warning('MCP stdio listTools failed', [
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
