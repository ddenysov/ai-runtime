<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentStateProcessorAssignment extends Model
{
    protected $fillable = [
        'agent_id',
        'agent_state_processor_id',
        'is_enabled',
        'trigger',
        'scope',
        'injection_title',
        'injection_instructions',
        'state_filters',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'state_filters' => 'array',
            'sort_order' => 'integer',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(AgentStateProcessor::class, 'agent_state_processor_id');
    }
}
