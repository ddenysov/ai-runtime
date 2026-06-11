<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentSchedule extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'uuid',
        'agent_id',
        'name',
        'enabled',
        'deliver_to_channel',
        'timezone',
        'schedule_type',
        'schedule_config',
        'message',
        'context_id',
        'metadata',
        'last_run_at',
        'last_run_id',
        'last_error',
        'next_run_at',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /**
     * Fingerprint of user-facing schedule fields. Used to skip queued runs
     * that were dispatched before the schedule was edited.
     */
    public function dispatchFingerprint(): string
    {
        return hash('sha256', json_encode([
            'enabled' => $this->enabled,
            'deliver_to_channel' => $this->deliver_to_channel,
            'schedule_type' => $this->schedule_type,
            'schedule_config' => $this->schedule_config,
            'timezone' => $this->timezone,
            'message' => $this->message,
            'context_id' => $this->context_id,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'deliver_to_channel' => 'boolean',
            'schedule_config' => 'array',
            'metadata' => 'array',
            'last_run_at' => 'datetime',
            'next_run_at' => 'datetime',
        ];
    }
}
