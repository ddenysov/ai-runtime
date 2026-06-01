<?php

namespace App\Mcp\Commands;

use App\Mcp\Models\McpServer;

final class CreateMcpServerHandler
{
    public function handle(CreateMcpServer $command): McpServer
    {
        return McpServer::query()->create([
            'uuid' => $command->uuid,
            'name' => $command->name,
            'transport' => $command->transport,
            'command' => $command->command,
            'args' => $command->args,
            'cwd' => $command->cwd,
            'env' => $command->env,
            'metadata' => $command->metadata,
            'enabled' => $command->enabled,
            'aggregate_version' => 0,
        ]);
    }
}
