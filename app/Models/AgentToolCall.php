<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
    ];

    protected function casts(): array
    {
        return [
            'arguments' => 'array',
            'result' => 'array',
            'applied_at' => 'datetime',
        ];
    }
}
