<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentRun extends Model
{
    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'agent_slug',
        'state',
        'input',
        'output',
        'workflow_resume_token',
        'conversation_state',
        'resumable_at',
        'attempts',
        'last_error_kind',
        'last_error_message',
        'next_attempt_at',
        'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'input' => 'array',
            'output' => 'array',
            'conversation_state' => 'array',
            'resumable_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }
}
