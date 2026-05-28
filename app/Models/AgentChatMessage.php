<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentChatMessage extends Model
{
    protected $fillable = [
        'thread_id',
        'role',
        'content',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'content' => 'array',
            'meta' => 'array',
        ];
    }
}
