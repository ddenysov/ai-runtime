<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentConversation extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'first_agent_id',
        'second_agent_id',
        'first_agent_context_id',
        'second_agent_context_id',
        'starter_prompt',
        'next_agent_id',
    ];

    public function firstAgent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'first_agent_id');
    }

    public function secondAgent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'second_agent_id');
    }

    public function nextAgent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'next_agent_id');
    }

    public function contextIdForAgent(Agent $agent): string
    {
        if ($agent->id === $this->first_agent_id) {
            return $this->first_agent_context_id;
        }

        if ($agent->id === $this->second_agent_id) {
            return $this->second_agent_context_id;
        }

        throw new \InvalidArgumentException('Agent does not belong to this conversation.');
    }

    public function otherAgent(Agent $agent): Agent
    {
        if ($agent->id === $this->first_agent_id) {
            return $this->secondAgent;
        }

        if ($agent->id === $this->second_agent_id) {
            return $this->firstAgent;
        }

        throw new \InvalidArgumentException('Agent does not belong to this conversation.');
    }
}
