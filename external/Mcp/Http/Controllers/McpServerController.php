<?php

namespace App\Mcp\Http\Controllers;

use App\Mcp\Commands\CreateMcpServer;
use App\Mcp\Commands\DeleteMcpServer;
use App\Mcp\Commands\TestMcpServer;
use App\Mcp\Commands\UpdateMcpServer;
use App\Mcp\Queries\ListMcpServers;
use App\Mcp\Http\Requests\DestroyMcpServerRequest;
use App\Mcp\Http\Requests\StoreMcpServerRequest;
use App\Mcp\Http\Requests\UpdateMcpServerRequest;
use App\Mcp\Http\Resources\McpServerResource;
use App\Mcp\Http\Resources\McpServerToolResource;
use App\Mcp\Models\McpServer;
use App\Mcp\Services\McpStdioServerToolLister;
use App\Shared\Application\Bus\AsyncCommandBus;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\QueryBus;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class McpServerController
{
    public function index(QueryBus $queries): JsonResponse
    {
        $servers = $queries->ask(new ListMcpServers);

        return response()->json([
            'data' => McpServerResource::collection($servers),
        ]);
    }

    public function show(McpServer $mcpServer): JsonResponse
    {
        return response()->json([
            'data' => new McpServerResource($mcpServer, true),
        ]);
    }

    public function tools(McpServer $mcpServer, McpStdioServerToolLister $lister): JsonResponse
    {
        $result = $lister->listTools($mcpServer);

        if (! $result['ok']) {
            return response()->json([
                'message' => $result['message'],
            ], 502);
        }

        return response()->json([
            'data' => McpServerToolResource::collection($result['tools']),
        ]);
    }

    public function store(StoreMcpServerRequest $request, CommandBus $commands): JsonResponse
    {
        $uuid = (string) Str::uuid();
        $cwd = $request->validated('cwd');
        if ($cwd === '') {
            $cwd = null;
        }

        $server = $commands->dispatch(new CreateMcpServer(
            uuid: $uuid,
            name: $request->validated('name'),
            transport: $request->validated('transport'),
            command: $request->validated('command'),
            args: $request->argumentList(),
            cwd: $cwd,
            env: $request->stringEnvironment(),
            metadata: $request->validated('metadata', null),
            enabled: (bool) $request->boolean('enabled', true),
        ));

        return response()->json([
            'data' => new McpServerResource($server, true),
        ], 201);
    }

    public function update(UpdateMcpServerRequest $request, McpServer $mcpServer, CommandBus $commands): JsonResponse
    {
        $cwd = null;
        if ($request->has('cwd')) {
            $v = $request->validated('cwd');
            $cwd = ($v === '' || $v === null) ? null : (string) $v;
        }

        $server = $commands->dispatch(new UpdateMcpServer(
            uuid: $mcpServer->uuid,
            expectedVersion: $request->expectedVersion(),
            hasName: $request->has('name'),
            name: $request->has('name') ? (string) $request->validated('name') : null,
            hasTransport: $request->has('transport'),
            transport: $request->has('transport') ? (string) $request->validated('transport') : null,
            hasCommand: $request->has('command'),
            command: $request->has('command') ? (string) $request->validated('command') : null,
            hasArgs: $request->has('args'),
            args: $request->argumentList(),
            hasCwd: $request->has('cwd'),
            cwd: $cwd,
            hasEnv: $request->has('env'),
            env: $request->has('env') ? $request->stringEnvironment() : null,
            hasMetadata: $request->has('metadata'),
            metadata: $request->has('metadata') ? $request->validated('metadata') : null,
            hasEnabled: $request->has('enabled'),
            enabled: $request->has('enabled') ? (bool) $request->validated('enabled') : null,
        ));

        return response()->json([
            'data' => new McpServerResource($server, true),
        ]);
    }

    public function destroy(DestroyMcpServerRequest $request, McpServer $mcpServer, CommandBus $commands): JsonResponse
    {
        $commands->dispatch(new DeleteMcpServer(
            uuid: $mcpServer->uuid,
            expectedVersion: $request->expectedVersion(),
        ));

        return new JsonResponse(null, 204);
    }

    public function test(McpServer $mcpServer, AsyncCommandBus $commands): JsonResponse
    {
        $commands->dispatch(new TestMcpServer($mcpServer->uuid));

        return response()->json([
            'data' => [
                'queued' => true,
                'message' => 'MCP server test has been queued.',
            ],
        ], 202);
    }
}
