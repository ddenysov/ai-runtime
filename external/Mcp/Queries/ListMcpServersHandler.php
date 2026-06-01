<?php

namespace App\Mcp\Queries;

use App\Mcp\Models\McpServer;
use Illuminate\Database\Eloquent\Collection;

final class ListMcpServersHandler
{
    /**
     * @return Collection<int, McpServer>
     */
    public function handle(ListMcpServers $query): Collection
    {
        return McpServer::query()
            ->orderByDesc('updated_at')
            ->get();
    }
}
