<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAgentRequest;
use App\Http\Requests\UpdateAgentRequest;
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
                'stateProcessorAssignments.processor.extractorAgent',
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
        $stateProcessors = $validated['state_processors'] ?? [];
        $agentAttributes = Arr::except($validated, ['tools', 'state_processors']);

        $agent = DB::transaction(function () use ($agentAttributes, $tools, $stateProcessors): Agent {
            $agent = Agent::query()->create([
                ...$agentAttributes,
                'input_modes' => $agentAttributes['input_modes'] ?? ['text/plain'],
                'output_modes' => $agentAttributes['output_modes'] ?? ['text/plain'],
                'subagents' => $agentAttributes['subagents'] ?? [],
                'timeout_seconds' => $agentAttributes['timeout_seconds'] ?? 120,
                'history_context_window' => $agentAttributes['history_context_window'] ?? 50000,
            ]);

            $this->syncAgentTools($agent, $tools);
            $this->syncStateProcessorAssignments($agent, $stateProcessors);

            $agent->load(['providerModel.provider', 'tools', 'stateProcessorAssignments.processor.extractorAgent']);
            $agent->createVersionSnapshot();

            return $agent;
        });

        return response()->json($agent->load([
            'providerModel.provider',
            'tools',
            'stateProcessorAssignments.processor.extractorAgent',
            'versions',
        ]), 201);
    }

    public function show(Agent $agent): JsonResponse
    {
        return response()->json(
            $agent->load([
                'providerModel.provider',
                'tools',
                'stateProcessorAssignments.processor.extractorAgent',
                'versions' => fn ($query) => $query->latest('version'),
            ])
        );
    }

    public function update(UpdateAgentRequest $request, Agent $agent): JsonResponse
    {
        $validated = $request->validated();
        $tools = $validated['tools'] ?? null;
        $stateProcessors = $validated['state_processors'] ?? null;
        $agentAttributes = Arr::except($validated, ['tools', 'state_processors']);

        DB::transaction(function () use ($agent, $agentAttributes, $tools, $stateProcessors): void {
            if ($agentAttributes !== []) {
                $agent->update($agentAttributes);
            }

            if (is_array($tools)) {
                $this->syncAgentTools($agent, $tools);
            }

            if (is_array($stateProcessors)) {
                $this->syncStateProcessorAssignments($agent, $stateProcessors);
            }
        });

        return response()->json(
            $agent->load([
                'providerModel.provider',
                'tools',
                'stateProcessorAssignments.processor.extractorAgent',
                'versions' => fn ($query) => $query->latest('version'),
            ])
        );
    }

    public function destroy(Agent $agent)
    {
        $agent->delete();

        return response()->noContent();
    }

    /**
     * @param  list<array<string, mixed>>  $tools
     */
    private function syncAgentTools(Agent $agent, array $tools): void
    {
        $agent->tools()->delete();

        foreach ($tools as $tool) {
            $agent->tools()->create([
                'slug' => $tool['slug'],
                'is_enabled' => filter_var($tool['is_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'config' => $tool['config'] ?? null,
            ]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $stateProcessors
     */
    private function syncStateProcessorAssignments(Agent $agent, array $stateProcessors): void
    {
        $agent->stateProcessorAssignments()->delete();

        foreach (array_values($stateProcessors) as $index => $assignment) {
            $agent->stateProcessorAssignments()->create([
                'agent_state_processor_id' => $assignment['agent_state_processor_id'],
                'is_enabled' => filter_var($assignment['is_enabled'] ?? true, FILTER_VALIDATE_BOOLEAN),
                'trigger' => $assignment['trigger'] ?? 'after_response',
                'scope' => $assignment['scope'] ?? 'conversation',
                'injection_title' => $assignment['injection_title'] ?? 'Runtime State',
                'injection_instructions' => $assignment['injection_instructions'] ?? null,
                'state_filters' => $assignment['state_filters'] ?? null,
                'sort_order' => $assignment['sort_order'] ?? $index,
            ]);
        }
    }
}
