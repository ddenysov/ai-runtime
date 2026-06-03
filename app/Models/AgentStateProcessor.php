<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgentStateProcessor extends Model
{
    protected $fillable = [
        'extractor_agent_id',
        'name',
        'slug',
        'instructions',
        'response_schema',
        'entity_types',
        'default_scope',
        'min_confidence',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'response_schema' => 'array',
            'entity_types' => 'array',
            'min_confidence' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function extractorAgent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'extractor_agent_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(AgentStateProcessorAssignment::class);
    }
}
