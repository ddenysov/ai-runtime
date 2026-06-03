<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class AgentStateEntry extends Model
{
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'group_id',
        'scope',
        'conversation_id',
        'agent_slug',
        'entity_type',
        'title',
        'summary',
        'content',
    ];

    protected function casts(): array
    {
        return [
            'content' => 'array',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(AgentStateGroup::class, 'group_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(
            AgentStateTag::class,
            'agent_state_entry_tag',
            'agent_state_entry_id',
            'agent_state_tag_id',
        );
    }
}
