<?php

namespace App\Mcp\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Mcp\Http\Requests\DestroyMcpServerRequest;
use App\Mcp\Http\Requests\StoreMcpServerRequest;
use App\Mcp\Http\Requests\UpdateMcpServerRequest;
use App\Mcp\Http\Resources\McpServerResource;
use App\Mcp\Http\Resources\McpServerToolResource;
use App\Mcp\Models\McpServer;
use App\Mcp\Services\McpStdioServerTester;
use App\Mcp\Services\McpStdioServerToolLister;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class McpServerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max($request->integer('per_page', 25), 1), 100);

        $servers = McpServer::query()
            ->when($request->filled('filter.search'), function ($query) use ($request): void {
                $search = (string) $request->input('filter.search');

                $query->where(function ($query) use ($search): void {
                    $query
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('command', 'like', "%{$search}%");
                });
            })
            ->when($request->filled('filter.enabled'), function ($query) use ($request): void {
                $query->where('enabled', $request->boolean('filter.enabled'));
            })
            ->orderByDesc('updated_at')
            ->paginate($perPage)
            ->appends($request->query());

        return response()->json($servers->through(
            fn (McpServer $server): McpServerResource => new McpServerResource($server),
        ));
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

    public function store(StoreMcpServerRequest $request): JsonResponse
    {
        $server = McpServer::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => $request->validated('name'),
            'transport' => $request->validated('transport'),
            'command' => $request->validated('command'),
            'args' => $request->argumentList(),
            'cwd' => $this->nullableString($request->validated('cwd')),
            'env' => $request->stringEnvironment(),
            'metadata' => $request->validated('metadata', null),
            'enabled' => (bool) $request->boolean('enabled', true),
            'aggregate_version' => 0,
        ]);

        return response()->json([
            'data' => new McpServerResource($server, true),
        ], 201);
    }

    public function update(UpdateMcpServerRequest $request, McpServer $mcpServer): JsonResponse
    {
        $server = DB::transaction(function () use ($request, $mcpServer): McpServer {
            /** @var McpServer $server */
            $server = McpServer::query()
                ->whereKey($mcpServer->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertExpectedVersion($server, $request->expectedVersion());

            if ($request->has('name')) {
                $server->name = (string) $request->validated('name');
            }

            if ($request->has('transport')) {
                $server->transport = (string) $request->validated('transport');
            }

            if ($request->has('command')) {
                $server->command = (string) $request->validated('command');
            }

            if ($request->has('args')) {
                $server->args = $request->argumentList() ?? [];
            }

            if ($request->has('cwd')) {
                $server->cwd = $this->nullableString($request->validated('cwd'));
            }

            if ($request->has('env')) {
                $server->env = $request->stringEnvironment();
            }

            if ($request->has('metadata')) {
                $server->metadata = $request->validated('metadata');
            }

            if ($request->has('enabled')) {
                $server->enabled = (bool) $request->validated('enabled');
            }

            $server->aggregate_version++;
            $server->save();

            return $server->refresh();
        });

        return response()->json([
            'data' => new McpServerResource($server, true),
        ]);
    }

    public function destroy(DestroyMcpServerRequest $request, McpServer $mcpServer): JsonResponse
    {
        DB::transaction(function () use ($request, $mcpServer): void {
            /** @var McpServer $server */
            $server = McpServer::query()
                ->whereKey($mcpServer->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertExpectedVersion($server, $request->expectedVersion());
            $server->delete();
        });

        return new JsonResponse(null, 204);
    }

    public function test(McpServer $mcpServer, McpStdioServerTester $tester): JsonResponse
    {
        $result = $tester->test($mcpServer);

        $mcpServer->forceFill([
            'last_tested_at' => now(),
            'last_test_status' => $result['ok'] ? 'ok' : 'error',
            'last_test_message' => $result['message'],
        ])->save();

        return response()->json([
            'data' => [
                ...$result,
                'server' => new McpServerResource($mcpServer->refresh()),
            ],
        ], $result['ok'] ? 200 : 502);
    }

    private function assertExpectedVersion(McpServer $server, int $expectedVersion): void
    {
        if ($server->aggregate_version === $expectedVersion) {
            return;
        }

        throw new HttpResponseException(response()->json([
            'message' => 'MCP server version conflict.',
            'errors' => [
                'expected_version' => [
                    "Expected version {$expectedVersion}, current version is {$server->aggregate_version}.",
                ],
            ],
        ], 409));
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
