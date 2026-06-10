<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentSchedule extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'uuid',
        'agent_id',
        'name',
        'enabled',
        'timezone',
        'schedule_type',
        'schedule_config',
        'message',
        'context_id',
        'metadata',
        'last_run_at',
        'last_run_id',
        'last_error',
        'next_run_at',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'schedule_config' => 'array',
            'metadata' => 'array',
            'last_run_at' => 'datetime',
            'next_run_at' => 'datetime',
        ];
    }
}
