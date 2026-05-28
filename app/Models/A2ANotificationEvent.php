<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class A2ANotificationEvent extends Model
{
    protected $table = 'a2a_notification_events';

    protected $fillable = [
        'kind',
        'task_id',
        'context_id',
        'payload',
        'headers',
        'source_ip',
        'processed_at',
        'processing_error',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'headers' => 'array',
            'processed_at' => 'datetime',
        ];
    }
}
