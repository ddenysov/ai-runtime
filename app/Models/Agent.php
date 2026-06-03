<?php

namespace App\Models;

use App\Channels\Models\AgentChannel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Agent extends Model
{
    protected $fillable = [
        'ai_provider_model_id',
        'slug',
        'name',
        'description',
        'is_active',
        'instructions',
        'input_modes',
        'output_modes',
        'skills',
        'subagents',
        'input_schema',
        'output_schema',
        'temperature',
        'max_tokens',
        'timeout_seconds',
        'history_context_window',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'instructions' => 'array',
            'input_modes' => 'array',
            'output_modes' => 'array',
            'skills' => 'array',
            'subagents' => 'array',
            'input_schema' => 'array',
            'output_schema' => 'array',
            'temperature' => 'decimal:2',
            'max_tokens' => 'integer',
            'timeout_seconds' => 'integer',
            'history_context_window' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function providerModel(): BelongsTo
    {
        return $this->belongsTo(AiProviderModel::class, 'ai_provider_model_id');
    }

    public function tools(): HasMany
    {
        return $this->hasMany(AgentTool::class);
    }

    public function stateProcessorAssignments(): HasMany
    {
        return $this->hasMany(AgentStateProcessorAssignment::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function stateProcessors(): BelongsToMany
    {
        return $this->belongsToMany(
            AgentStateProcessor::class,
            'agent_state_processor_assignments',
            'agent_id',
            'agent_state_processor_id',
        )->withPivot([
            'is_enabled',
            'trigger',
            'scope',
            'injection_title',
            'injection_instructions',
            'state_filters',
            'sort_order',
        ])->withTimestamps();
    }

    public function channels(): HasMany
    {
        return $this->hasMany(AgentChannel::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(AgentVersion::class);
    }

    public function currentVersion(): AgentVersion
    {
        return $this->versions()
            ->latest('version')
            ->firstOrFail();
    }

    public function createVersionSnapshot(): AgentVersion
    {
        $version = ((int) $this->versions()->max('version')) + 1;

        return $this->versions()->create([
            'version' => $version,
            'configuration' => $this->snapshotConfiguration(),
            'published_at' => now(),
        ]);
    }

    public function snapshotConfiguration(): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'ai_provider_model_id' => $this->ai_provider_model_id,
            'instructions' => $this->instructions ?? [],
            'input_modes' => $this->input_modes ?? ['text/plain'],
            'output_modes' => $this->output_modes ?? ['text/plain'],
            'skills' => $this->skills ?? $this->defaultSkills(),
            'subagents' => $this->subagents ?? [],
            'tools' => $this->tools()
                ->where('is_enabled', true)
                ->get()
                ->map(fn (AgentTool $tool): array => [
                    'slug' => $tool->slug,
                    'config' => $tool->config ?? [],
                ])
                ->all(),
            'state_processors' => $this->stateProcessorAssignments()
                ->where('is_enabled', true)
                ->with(['processor.extractorAgent'])
                ->get()
                ->filter(fn (AgentStateProcessorAssignment $assignment): bool => $assignment->processor?->is_active === true)
                ->map(fn (AgentStateProcessorAssignment $assignment): array => [
                    'id' => $assignment->id,
                    'processor_id' => $assignment->processor->id,
                    'processor_slug' => $assignment->processor->slug,
                    'processor_name' => $assignment->processor->name,
                    'extractor_agent_slug' => $assignment->processor->extractorAgent?->slug,
                    'instructions' => $assignment->processor->instructions,
                    'response_schema' => $assignment->processor->response_schema,
                    'entity_types' => $assignment->processor->entity_types,
                    'default_scope' => $assignment->processor->default_scope,
                    'min_confidence' => $assignment->processor->min_confidence,
                    'trigger' => $assignment->trigger,
                    'scope' => $assignment->scope,
                    'injection_title' => $assignment->injection_title,
                    'injection_instructions' => $assignment->injection_instructions,
                    'state_filters' => $assignment->state_filters ?? [],
                    'sort_order' => $assignment->sort_order,
                ])
                ->values()
                ->all(),
            'input_schema' => $this->input_schema,
            'output_schema' => $this->output_schema,
            'temperature' => $this->temperature,
            'max_tokens' => $this->max_tokens,
            'timeout_seconds' => $this->timeout_seconds,
            'history_context_window' => $this->history_context_window,
            'metadata' => $this->metadata ?? [],
        ];
    }

    public function toRuntimeDefinition(): array
    {
        return [
            ...$this->snapshotConfiguration(),
            'ai_provider_model_slug' => $this->providerModel?->slug,
        ];
    }

    private function defaultSkills(): array
    {
        return [
            [
                'id' => $this->slug,
                'name' => $this->name,
                'description' => $this->description,
                'tags' => ['runtime', 'agent'],
                'examples' => [],
            ],
        ];
    }
}
