<?php

namespace App\Channels\Models;

use App\Models\Agent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgentChannel extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'uuid',
        'agent_id',
        'name',
        'description',
        'type',
        'settings',
        'metadata',
        'enabled',
        'aggregate_version',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function threads(): HasMany
    {
        return $this->hasMany(AgentChannelThread::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'settings' => 'encrypted:array',
            'metadata' => 'array',
            'enabled' => 'boolean',
            'aggregate_version' => 'integer',
        ];
    }
}
