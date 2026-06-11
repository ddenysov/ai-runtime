<?php

namespace App\Jobs;

use App\A2A\SendMessageAction;
use App\A2A\TaskPayloadFactory;
use App\Channels\Services\AgentChannelDeliveryResolver;
use App\Models\AgentSchedule;
use App\Scheduling\AgentScheduleCalculator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class RunScheduledAgent implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public int $agentScheduleId,
        public ?string $scheduledFor = null,
        public bool $recalculateNextRun = false,
        public ?string $dispatchFingerprint = null,
    ) {}

    public function handle(
        SendMessageAction $sendMessage,
        TaskPayloadFactory $payloads,
        AgentScheduleCalculator $calculator,
        AgentChannelDeliveryResolver $channelDelivery,
    ): void {
        $schedule = AgentSchedule::query()
            ->with('agent')
            ->find($this->agentScheduleId);

        if (! $schedule instanceof AgentSchedule) {
            return;
        }

        if (! $schedule->enabled) {
            return;
        }

        if ($this->dispatchFingerprint !== null
            && ! hash_equals($this->dispatchFingerprint, $schedule->dispatchFingerprint())) {
            Log::info('Scheduled agent run skipped: schedule changed after dispatch.', [
                'agent_schedule_id' => $schedule->id,
                'scheduled_for' => $this->scheduledFor,
            ]);

            return;
        }

        $agent = $schedule->agent;

        if ($agent === null || ! $agent->is_active) {
            $schedule->update([
                'last_error' => 'Agent is missing or inactive.',
            ]);

            return;
        }

        $runId = (string) Str::uuid();
        $contextId = $schedule->context_id ?: (string) Str::uuid();
        $metadata = [
            'agent_run_id' => $runId,
            'contextId' => $contextId,
            'source' => 'agent_schedule',
            'agent_schedule_id' => $schedule->id,
            'scheduled_for' => $this->scheduledFor,
        ];

        if ($schedule->deliver_to_channel) {
            $destination = $channelDelivery->resolveForAgent($agent);

            if ($destination === null) {
                $schedule->update([
                    'last_error' => 'No delivery destination found. Enable an agent channel and send at least one inbound message first.',
                ]);

                Log::warning('Scheduled agent run skipped: no channel delivery destination.', [
                    'agent_schedule_id' => $schedule->id,
                    'agent_slug' => $agent->slug,
                ]);

                return;
            }

            $metadata['delivery_channel'] = $destination->deliveryChannel;

            if ($schedule->context_id === null && $destination->contextId !== null) {
                $contextId = $destination->contextId;
                $metadata['contextId'] = $contextId;
            }
        }

        try {
            $sendMessage->handle(
                agentSlug: $agent->slug,
                message: $payloads->userMessage($schedule->message),
                metadata: $metadata,
            );

            $updates = [
                'last_run_at' => now(),
                'last_run_id' => $runId,
                'last_error' => null,
            ];

            if ($this->recalculateNextRun) {
                $updates['next_run_at'] = $calculator->nextRunAt($schedule);
            }

            $schedule->update($updates);

            Log::info('Scheduled agent run queued.', [
                'agent_schedule_id' => $schedule->id,
                'agent_slug' => $agent->slug,
                'agent_run_id' => $runId,
                'scheduled_for' => $this->scheduledFor,
            ]);
        } catch (Throwable $exception) {
            $schedule->update([
                'last_error' => $exception->getMessage(),
            ]);

            Log::warning('Scheduled agent run failed to queue.', [
                'agent_schedule_id' => $schedule->id,
                'agent_slug' => $agent->slug,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }
}
