<?php

namespace App\Http\Controllers;

use App\Models\AgentStateProcessor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedInclude;
use Spatie\QueryBuilder\QueryBuilder;

class AgentStateProcessorController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max($request->integer('per_page', 10), 1), 50);

        $processors = QueryBuilder::for(AgentStateProcessor::class)
            ->allowedFilters(
                AllowedFilter::callback('search', function (Builder $query, mixed $value): void {
                    $query->where(function (Builder $query) use ($value): void {
                        $query
                            ->where('name', 'like', "%{$value}%")
                            ->orWhere('slug', 'like', "%{$value}%")
                            ->orWhere('instructions', 'like', "%{$value}%");
                    });
                }),
                AllowedFilter::exact('is_active'),
                AllowedFilter::exact('extractor_agent_id'),
            )
            ->allowedSorts('name', 'is_active', 'created_at', 'updated_at')
            ->allowedIncludes(
                'extractorAgent',
                AllowedInclude::count('assignmentsCount'),
            )
            ->defaultSort('-updated_at')
            ->paginate($perPage)
            ->appends($request->query());

        return response()->json($processors);
    }

    public function store(Request $request): JsonResponse
    {
        $processor = AgentStateProcessor::query()->create($this->validated($request));

        return response()->json($processor->load('extractorAgent'), 201);
    }

    public function show(AgentStateProcessor $agentStateProcessor): JsonResponse
    {
        return response()->json($agentStateProcessor->load('extractorAgent'));
    }

    public function update(Request $request, AgentStateProcessor $agentStateProcessor): JsonResponse
    {
        $agentStateProcessor->update($this->validated($request, $agentStateProcessor));

        return response()->json($agentStateProcessor->load('extractorAgent'));
    }

    public function destroy(AgentStateProcessor $agentStateProcessor): JsonResponse
    {
        $agentStateProcessor->delete();

        return response()->json(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?AgentStateProcessor $processor = null): array
    {
        return $request->validate([
            'extractor_agent_id' => ['required', 'integer', 'exists:agents,id'],
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required',
                'string',
                'max:255',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('agent_state_processors', 'slug')->ignore($processor?->id),
            ],
            'instructions' => ['required', 'string', 'max:12000'],
            'response_schema' => ['nullable', 'array'],
            'entity_types' => ['nullable', 'array'],
            'entity_types.*' => ['required', 'string', 'max:100'],
            'default_scope' => ['sometimes', 'string', 'in:conversation,global'],
            'min_confidence' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}
