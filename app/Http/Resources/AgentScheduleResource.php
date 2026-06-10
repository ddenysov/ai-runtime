<?php

namespace App\Http\Resources;

use App\Models\AgentSchedule;
use App\Scheduling\AgentScheduleCalculator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AgentSchedule
 */
class AgentScheduleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $calculator = app(AgentScheduleCalculator::class);

        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'agent_id' => $this->agent_id,
            'name' => $this->name,
            'enabled' => $this->enabled,
            'deliver_to_channel' => $this->deliver_to_channel,
            'timezone' => $this->timezone,
            'schedule_type' => $this->schedule_type,
            'schedule_config' => $this->schedule_config,
            'cron_expression' => $calculator->cronExpression($this->resource),
            'message' => $this->message,
            'context_id' => $this->context_id,
            'metadata' => $this->metadata,
            'last_run_at' => $this->last_run_at?->toIso8601String(),
            'last_run_id' => $this->last_run_id,
            'last_error' => $this->last_error,
            'next_run_at' => $this->next_run_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
