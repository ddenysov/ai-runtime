<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAgentRequest;
use App\Models\Agent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedInclude;
use Spatie\QueryBuilder\QueryBuilder;

class AgentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max($request->integer('per_page', 10), 1), 50);

        $agents = QueryBuilder::for(Agent::class)
            ->allowedFilters(
                AllowedFilter::callback('search', function (Builder $query, mixed $value): void {
                    $query->where(function (Builder $query) use ($value): void {
                        $query
                            ->where('name', 'like', "%{$value}%")
                            ->orWhere('slug', 'like', "%{$value}%")
                            ->orWhere('description', 'like', "%{$value}%");
                    });
                }),
                AllowedFilter::exact('is_active'),
                AllowedFilter::exact('ai_provider_model_id'),
            )
            ->allowedSorts(
                'name',
                'is_active',
                'created_at',
                'updated_at',
            )
            ->allowedIncludes(
                'providerModel.provider',
                'tools',
                AllowedInclude::count('versionsCount'),
                AllowedInclude::count('toolsCount'),
            )
            ->defaultSort('-updated_at')
            ->paginate($perPage)
            ->appends($request->query());

        return response()->json($agents);
    }

    public function store(StoreAgentRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $tools = $validated['tools'] ?? [];
        $agentAttributes = Arr::except($validated, 'tools');

        $agent = DB::transaction(function () use ($agentAttributes, $tools): Agent {
            $agent = Agent::query()->create([
                ...$agentAttributes,
                'input_modes' => $agentAttributes['input_modes'] ?? ['text/plain'],
                'output_modes' => $agentAttributes['output_modes'] ?? ['text/plain'],
                'subagents' => $agentAttributes['subagents'] ?? [],
                'timeout_seconds' => $agentAttributes['timeout_seconds'] ?? 120,
                'history_context_window' => $agentAttributes['history_context_window'] ?? 50000,
            ]);

            foreach ($tools as $tool) {
                $agent->tools()->create([
                    'slug' => $tool['slug'],
                    'is_enabled' => $tool['is_enabled'] ?? true,
                    'config' => $tool['config'] ?? null,
                ]);
            }

            $agent->load(['providerModel.provider', 'tools']);
            $agent->createVersionSnapshot();

            return $agent;
        });

        return response()->json($agent->load(['providerModel.provider', 'tools', 'versions']), 201);
    }

    public function show(Agent $agent): JsonResponse
    {
        return response()->json(
            $agent->load([
                'providerModel.provider',
                'tools',
                'versions' => fn ($query) => $query->latest('version'),
            ])
        );
    }

    public function destroy(Agent $agent)
    {
        $agent->delete();

        return response()->noContent();
    }
}
