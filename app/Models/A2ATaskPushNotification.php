<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class A2ATaskPushNotification extends Model
{
    protected $table = 'a2a_task_push_notifications';

    protected $fillable = [
        'a2a_task_id',
        'url',
        'authentication',
        'notification_token',
        'last_status',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'authentication' => 'array',
        ];
    }
}
