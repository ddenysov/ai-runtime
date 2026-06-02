<?php

namespace App\Models;

use App\Channels\Models\AgentChannel;
use Illuminate\Database\Eloquent\Model;
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
