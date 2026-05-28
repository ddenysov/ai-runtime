<?php

namespace App\Jobs;

use App\Models\AgentRun;
use App\Models\AgentToolCall;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class ResumeParentAgentJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $agentRunId,
    ) {}

    public function handle(): void
    {
        DB::transaction(function (): void {
            $run = AgentRun::query()
                ->whereKey($this->agentRunId)
                ->lockForUpdate()
                ->first();

            if ($run === null || $run->state !== 'waiting_for_tool') {
                return;
            }

            $toolCall = AgentToolCall::query()
                ->where('agent_run_id', $run->id)
                ->whereIn('state', ['completed', 'failed'])
                ->whereNull('applied_at')
                ->first();

            if ($toolCall === null) {
                return;
            }

            $run->update([
                'state' => $toolCall->state === 'completed' ? 'completed' : 'failed',
                'output' => [
                    'tool_call_id' => $toolCall->id,
                    'tool_state' => $toolCall->state,
                    'tool_result' => $toolCall->result,
                    'tool_error' => $toolCall->error,
                ],
            ]);

            $toolCall->update(['applied_at' => now()]);

            $parentChildTaskId = $run->input['parent_child_task_id'] ?? null;

            if (is_int($parentChildTaskId) || (is_string($parentChildTaskId) && is_numeric($parentChildTaskId))) {
                ProcessA2AChildTask::dispatch((int) $parentChildTaskId)->afterCommit();
            }
        });
    }
}
