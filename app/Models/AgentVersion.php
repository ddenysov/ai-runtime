<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentVersion extends Model
{
    protected $fillable = [
        'agent_id',
        'version',
        'configuration',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'configuration' => 'array',
            'published_at' => 'datetime',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
