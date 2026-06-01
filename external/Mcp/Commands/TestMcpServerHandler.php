<?php

namespace App\Mcp\Commands;

use App\Mcp\Models\McpServer;
use App\Mcp\Services\McpStdioServerTester;

final class TestMcpServerHandler
{
    public function __construct(
        private McpStdioServerTester $tester,
    ) {}

    /**
     * @return array{ok: bool, message: string, tools_count?: int}
     */
    public function handle(TestMcpServer $command): array
    {
        $server = McpServer::query()
            ->where('uuid', $command->serverUuid)
            ->firstOrFail();

        $result = $this->tester->test($server);

        $server->forceFill([
            'last_tested_at' => now(),
            'last_test_status' => $result['ok'] ? 'ok' : 'error',
            'last_test_message' => $result['message'],
        ])->save();

        return $result;
    }
}
