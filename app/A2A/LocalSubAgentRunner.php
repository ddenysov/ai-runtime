<?php

namespace App\A2A;

use App\Jobs\ProcessA2AChildTask;
use App\Models\A2AChildTask;
use App\Models\AgentRun;
use App\Models\AgentToolCall;
use Illuminate\Support\Str;

class LocalSubAgentRunner
{
    public function start(string $parentAgentSlug, string $childAgentSlug, string $prompt, ?string $nestedChildAgentSlug = null): array
    {
        $run = AgentRun::query()->create([
            'id' => (string) Str::uuid(),
            'agent_slug' => $parentAgentSlug,
            'state' => 'waiting_for_tool',
            'input' => ['prompt' => $prompt],
            'resumable_at' => now(),
        ]);
        $invocations = app(A2AInvocationGuard::class);
        $rootInvocation = $invocations->rootInvocation(null, $run->id, $parentAgentSlug);
        $invocation = $invocations->authorizeFromInvocation(
            parentInvocation: $rootInvocation,
            parentTaskId: null,
            parentAgentRunId: $run->id,
            parentAgentSlug: $parentAgentSlug,
            childAgentSlug: $childAgentSlug,
        );
        $run->update([
            'input' => [
                'prompt' => $prompt,
                'invocation' => $rootInvocation,
            ],
        ]);

        $toolCall = AgentToolCall::query()->create([
            'id' => (string) Str::uuid(),
            'agent_run_id' => $run->id,
            'tool_name' => 'remote_a2a_agent',
            'state' => 'waiting',
            'arguments' => [
                'agent_slug' => $childAgentSlug,
                'message' => $prompt,
                'nested_agent_slug' => $nestedChildAgentSlug,
                'invocation' => $invocation,
            ],
        ]);

        $requestPayload = [
            'message' => $prompt,
            'invocation' => $invocation,
        ];

        if ($nestedChildAgentSlug !== null && $nestedChildAgentSlug !== '') {
            $requestPayload['nested_agent_slug'] = $nestedChildAgentSlug;
            $requestPayload['nested_message'] = "Nested subagent check for {$childAgentSlug}: {$prompt}";
        }

        $childTask = A2AChildTask::query()->create([
            'agent_run_id' => $run->id,
            'tool_call_id' => $toolCall->id,
            'remote_agent_slug' => $childAgentSlug,
            'remote_task_id' => (string) Str::uuid(),
            'remote_context_id' => (string) Str::uuid(),
            'state' => A2AState::SUBMITTED,
            'request_payload' => $requestPayload,
        ]);

        ProcessA2AChildTask::dispatch($childTask->id);

        return [
            'run' => $run->fresh(),
            'tool_call' => $toolCall->fresh(),
            'child_task' => $childTask->fresh(),
        ];
    }
}
