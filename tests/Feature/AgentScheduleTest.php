<?php

namespace Tests\Feature;

use App\A2A\SendMessageAction;
use App\Jobs\RunScheduledAgent;
use App\Models\Agent;
use App\Models\AgentSchedule;
use App\Models\AiProvider;
use App\Scheduling\AgentScheduleCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AgentScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_switching_from_interval_to_daily_moves_next_run_into_the_future(): void
    {
        $agent = $this->createAgent();
        $calculator = app(AgentScheduleCalculator::class);

        $schedule = AgentSchedule::query()->create([
            'uuid' => (string) Str::uuid(),
            'agent_id' => $agent->id,
            'name' => 'Inbox check',
            'enabled' => true,
            'timezone' => 'UTC',
            'schedule_type' => 'interval',
            'schedule_config' => ['every_minutes' => 1],
            'message' => 'Check inbox',
            'next_run_at' => now()->addMinute(),
        ]);

        $schedule->schedule_type = 'daily';
        $schedule->schedule_config = [
            'time' => '09:00',
            'days_of_week' => [1, 2, 3, 4, 5],
        ];
        $schedule->next_run_at = $calculator->nextRunAt($schedule);
        $schedule->save();

        $schedule->refresh();

        $this->assertSame('daily', $schedule->schedule_type);
        $this->assertTrue($schedule->next_run_at->greaterThan(now()->addMinutes(2)));
    }

    public function test_stale_dispatch_fingerprint_skips_run(): void
    {
        $agent = $this->createAgent();

        $schedule = AgentSchedule::query()->create([
            'uuid' => (string) Str::uuid(),
            'agent_id' => $agent->id,
            'name' => 'Inbox check',
            'enabled' => true,
            'timezone' => 'UTC',
            'schedule_type' => 'interval',
            'schedule_config' => ['every_minutes' => 1],
            'message' => 'Check inbox',
            'next_run_at' => now(),
        ]);

        $staleFingerprint = $schedule->dispatchFingerprint();

        $schedule->update([
            'schedule_type' => 'daily',
            'schedule_config' => [
                'time' => '09:00',
                'days_of_week' => [1, 2, 3, 4, 5],
            ],
            'next_run_at' => app(AgentScheduleCalculator::class)->nextRunAt($schedule->refresh()),
        ]);

        $this->mock(SendMessageAction::class, function ($mock): void {
            $mock->shouldReceive('handle')->never();
        });

        (new RunScheduledAgent(
            agentScheduleId: $schedule->id,
            scheduledFor: now()->subMinute()->toIso8601String(),
            dispatchFingerprint: $staleFingerprint,
        ))->handle(
            app(SendMessageAction::class),
            app(\App\A2A\TaskPayloadFactory::class),
            app(AgentScheduleCalculator::class),
            app(\App\Channels\Services\AgentChannelDeliveryResolver::class),
        );
    }

    private function createAgent(): Agent
    {
        $provider = AiProvider::query()->create([
            'slug' => uniqid('schedule-gemini-'),
            'name' => 'Schedule Gemini',
            'type' => 'gemini',
            'credentials' => [
                'key' => 'test-key',
            ],
        ]);

        $providerModel = $provider->models()->create([
            'slug' => uniqid('schedule-model-'),
            'name' => 'Schedule Model',
            'model' => uniqid('gemini-flash-'),
            'is_active' => true,
        ]);

        return Agent::query()->create([
            'slug' => 'schedule-agent',
            'name' => 'Schedule Agent',
            'ai_provider_model_id' => $providerModel->id,
            'instructions' => [],
            'is_active' => true,
        ]);
    }
}
