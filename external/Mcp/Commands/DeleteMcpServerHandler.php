<?php

namespace App\Mcp\Commands;

use App\Mcp\Models\McpServer;
use App\Shared\Domain\Concurrency\ConcurrencyConflictException;
use Illuminate\Support\Facades\DB;

final class DeleteMcpServerHandler
{
    public function handle(DeleteMcpServer $command): void
    {
        DB::transaction(function () use ($command): void {
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

            $server->delete();
        });
    }
}
