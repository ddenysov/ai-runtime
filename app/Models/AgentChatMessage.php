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

    protected function asJson($value, $flags = 0)
    {
        return json_encode($value, $flags | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
