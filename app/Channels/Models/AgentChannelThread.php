<?php

namespace App\Channels\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentChannelThread extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'agent_channel_id',
        'external_chat_id',
        'context_id',
    ];

    public function channel(): BelongsTo
    {
        return $this->belongsTo(AgentChannel::class, 'agent_channel_id');
    }
}
