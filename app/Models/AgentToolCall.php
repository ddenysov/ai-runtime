<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentToolCall extends Model
{
    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'agent_run_id',
        'tool_name',
        'state',
        'arguments',
        'result',
        'applied_at',
        'error',
        'error_kind',
    ];

    protected function casts(): array
    {
        return [
            'arguments' => 'array',
            'result' => 'array',
            'applied_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'agent_run_id');
    }
}
