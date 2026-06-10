<?php

namespace App\Console\Commands;

use App\Jobs\RunScheduledAgent;
use App\Models\AgentSchedule;
use App\Scheduling\AgentScheduleCalculator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

class RunDueAgentSchedulesCommand extends Command
{
    protected $signature = 'agents:run-schedules
                            {--dry-run : Report due schedules without dispatching jobs}
                            {--limit=50 : Maximum schedules to process per run}';

    protected $description = 'Dispatch jobs for agent schedules that are due to run';

    public function handle(AgentScheduleCalculator $calculator): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(1, (int) $this->option('limit'));
        $count = 0;

        AgentSchedule::query()
            ->where('enabled', true)
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', now())
            ->orderBy('next_run_at')
            ->limit($limit)
            ->get()
            ->each(function (AgentSchedule $schedule) use ($calculator, $dryRun, &$count): void {
                $count++;
                $scheduledFor = $schedule->next_run_at?->toIso8601String();

                if ($dryRun) {
                    return;
                }

                $nextRunAt = $calculator->nextRunAt(
                    $schedule,
                    $schedule->next_run_at?->copy()->addSecond(),
                );

                $schedule->update([
                    'next_run_at' => $nextRunAt,
                ]);

                RunScheduledAgent::dispatch(
                    agentScheduleId: $schedule->id,
                    scheduledFor: $scheduledFor,
                );
            });

        $this->info(sprintf(
            'Due agent schedules: %d%s',
            $count,
            $dryRun ? ' dry-run' : '',
        ));

        Log::info('Agent schedule scan completed.', [
            'due_count' => $count,
            'dry_run' => $dryRun,
        ]);

        return SymfonyCommand::SUCCESS;
    }
}
