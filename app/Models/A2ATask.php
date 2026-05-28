<?php

namespace App\Models;

use App\A2A\A2AState;
use Illuminate\Database\Eloquent\Model;

class A2ATask extends Model
{
    protected $table = 'a2a_tasks';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'context_id',
        'agent_slug',
        'state',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'state' => A2AState::class,
            'payload' => 'array',
        ];
    }
}
