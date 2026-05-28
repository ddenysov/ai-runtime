<?php

namespace App\Console\Commands;

use App\A2A\A2AState;
use App\Jobs\ProcessA2AChildTask;
use App\Jobs\ProcessA2ATask;
use App\Jobs\ResumeParentAgentJob;
use App\Models\A2AChildTask;
use App\Models\A2ATask;
use App\Models\AgentRun;
use App\Models\AgentToolCall;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

class A2ARecoverStaleCommand extends Command
{
    protected $signature = 'a2a:recover-stale
                            {--dry-run : Report recoverable records without dispatching jobs}
                            {--limit=100 : Maximum records per recovery group}';

    protected $description = 'Recover stale A2A tasks, child tasks, and parent resumes';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(1, (int) $this->option('limit'));
        $staleBefore = now()->subMinutes((int) config('runtime-agents.recovery.stale_after_minutes', 5));
        $counts = [
            'tasks' => 0,
            'child_tasks' => 0,
            'resumes' => 0,
        ];

        A2ATask::query()
            ->whereIn('state', [A2AState::SUBMITTED->value, A2AState::WORKING->value])
            ->where(function ($query) use ($staleBefore): void {
                $query
                    ->whereNotNull('next_attempt_at')
                    ->where('next_attempt_at', '<=', now())
                    ->orWhere(function ($query) use ($staleBefore): void {
                        $query
                            ->whereNull('next_attempt_at')
                            ->where('updated_at', '<=', $staleBefore);
                    });
            })
            ->limit($limit)
            ->get()
            ->each(function (A2ATask $task) use ($dryRun, &$counts): void {
                $counts['tasks']++;

                if (! $dryRun) {
                    ProcessA2ATask::dispatch($task->id);
                }
            });

        A2AChildTask::query()
            ->whereIn('state', [A2AState::SUBMITTED->value, A2AState::WORKING->value])
            ->where(function ($query) use ($staleBefore): void {
                $query
                    ->whereNotNull('next_attempt_at')
                    ->where('next_attempt_at', '<=', now())
                    ->orWhere(function ($query) use ($staleBefore): void {
                        $query
                            ->whereNull('next_attempt_at')
                            ->where('updated_at', '<=', $staleBefore);
                    });
            })
            ->limit($limit)
            ->get()
            ->each(function (A2AChildTask $childTask) use ($dryRun, &$counts): void {
                $counts['child_tasks']++;

                if (! $dryRun) {
                    ProcessA2AChildTask::dispatch($childTask->id);
                }
            });

        AgentToolCall::query()
            ->whereIn('state', ['completed', 'failed'])
            ->whereNull('applied_at')
            ->whereHas('run', function ($query): void {
                $query
                    ->where('state', 'waiting_for_tool')
                    ->whereNotNull('workflow_resume_token');
            })
            ->limit($limit)
            ->get()
            ->each(function (AgentToolCall $toolCall) use ($dryRun, &$counts): void {
                $counts['resumes']++;

                if (! $dryRun) {
                    ResumeParentAgentJob::dispatch($toolCall->agent_run_id);
                }
            });

        AgentRun::query()
            ->where('state', 'waiting_for_tool')
            ->whereNotNull('workflow_resume_token')
            ->where(function ($query) use ($staleBefore): void {
                $query
                    ->whereNotNull('next_attempt_at')
                    ->where('next_attempt_at', '<=', now())
                    ->orWhere(function ($query) use ($staleBefore): void {
                        $query
                            ->whereNull('next_attempt_at')
                            ->where('resumable_at', '<=', $staleBefore);
                    });
            })
            ->limit($limit)
            ->get()
            ->each(function (AgentRun $run) use ($dryRun, &$counts): void {
                $hasPendingResult = AgentToolCall::query()
                    ->where('agent_run_id', $run->id)
                    ->whereIn('state', ['completed', 'failed'])
                    ->whereNull('applied_at')
                    ->exists();

                if (! $hasPendingResult) {
                    return;
                }

                $counts['resumes']++;

                if (! $dryRun) {
                    ResumeParentAgentJob::dispatch($run->id);
                }
            });

        $this->info(sprintf(
            'Recovered candidates: tasks=%d child_tasks=%d resumes=%d%s',
            $counts['tasks'],
            $counts['child_tasks'],
            $counts['resumes'],
            $dryRun ? ' dry-run' : '',
        ));
        Log::info('A2A stale recovery scan completed.', [
            ...$counts,
            'dry_run' => $dryRun,
        ]);

        return SymfonyCommand::SUCCESS;
    }
}
