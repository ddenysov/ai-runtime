<?php

namespace App\Mcp;

use App\Mcp\Commands\CreateMcpServer;
use App\Mcp\Commands\CreateMcpServerHandler;
use App\Mcp\Commands\DeleteMcpServer;
use App\Mcp\Commands\DeleteMcpServerHandler;
use App\Mcp\Commands\TestMcpServer;
use App\Mcp\Commands\TestMcpServerHandler;
use App\Mcp\Commands\UpdateMcpServer;
use App\Mcp\Commands\UpdateMcpServerHandler;
use App\Mcp\Queries\ListMcpServers;
use App\Mcp\Queries\ListMcpServersHandler;
use App\Shared\Application\Bus\CommandBusRegistry;
use App\Shared\Application\Bus\QueryBusRegistry;
use Illuminate\Support\ServiceProvider;

final class McpServiceProvider extends ServiceProvider
{
    public function boot(CommandBusRegistry $commands, QueryBusRegistry $queries): void
    {
        $commands->register([
            CreateMcpServer::class => CreateMcpServerHandler::class,
            UpdateMcpServer::class => UpdateMcpServerHandler::class,
            DeleteMcpServer::class => DeleteMcpServerHandler::class,
            TestMcpServer::class => TestMcpServerHandler::class,
        ]);

        $queries->register([
            ListMcpServers::class => ListMcpServersHandler::class,
        ]);
    }
}
