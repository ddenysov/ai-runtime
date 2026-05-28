<?php

namespace App\Models;

use App\A2A\A2AState;
use Illuminate\Database\Eloquent\Model;

class A2AChildTask extends Model
{
    protected $table = 'a2a_child_tasks';

    protected $fillable = [
        'agent_run_id',
        'tool_call_id',
        'remote_agent_slug',
        'remote_task_id',
        'remote_context_id',
        'state',
        'request_payload',
        'last_notification',
    ];

    protected function casts(): array
    {
        return [
            'state' => A2AState::class,
            'request_payload' => 'array',
            'last_notification' => 'array',
        ];
    }
}
