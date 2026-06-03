<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class AgentStateTag extends Model
{
    protected $fillable = [
        'name',
        'slug',
    ];

    public function entries(): BelongsToMany
    {
        return $this->belongsToMany(
            AgentStateEntry::class,
            'agent_state_entry_tag',
            'agent_state_tag_id',
            'agent_state_entry_id',
        );
    }
}
