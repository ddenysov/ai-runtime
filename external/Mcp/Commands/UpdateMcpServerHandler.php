<?php

namespace App\Mcp\Commands;

use App\Mcp\Models\McpServer;
use App\Shared\Domain\Concurrency\ConcurrencyConflictException;
use Illuminate\Support\Facades\DB;

final class UpdateMcpServerHandler
{
    public function handle(UpdateMcpServer $command): McpServer
    {
        return DB::transaction(function () use ($command): McpServer {
            $server = McpServer::query()
                ->where('uuid', $command->uuid)
                ->lockForUpdate()
                ->firstOrFail();

            if ($server->aggregate_version !== $command->expectedVersion) {
                throw new ConcurrencyConflictException(
                    aggregateUuid: $command->uuid,
                    expectedVersion: $command->expectedVersion,
                    actualVersion: $server->aggregate_version,
                );
            }

            if ($command->hasName && $command->name !== null) {
                $server->name = $command->name;
            }
            if ($command->hasTransport && $command->transport !== null) {
                $server->transport = $command->transport;
            }
            if ($command->hasCommand && $command->command !== null) {
                $server->command = $command->command;
            }
            if ($command->hasArgs) {
                $server->args = $command->args ?? [];
            }
            if ($command->hasCwd) {
                $server->cwd = $command->cwd;
            }
            if ($command->hasEnv) {
                $server->env = $command->env ?? [];
            }
            if ($command->hasMetadata) {
                $server->metadata = $command->metadata;
            }
            if ($command->hasEnabled && $command->enabled !== null) {
                $server->enabled = $command->enabled;
            }

            $server->aggregate_version = $server->aggregate_version + 1;
            $server->save();

            return $server->refresh();
        });
    }
}
