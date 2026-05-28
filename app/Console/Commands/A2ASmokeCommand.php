<?php

namespace App\Console\Commands;

use App\A2A\A2AInvocationGuard;
use App\A2A\A2AInvocationLimitExceeded;
use App\A2A\A2AState;
use App\A2A\RuntimeAgentTaskRepository;
use App\A2A\SendMessageAction;
use App\A2A\TaskPayloadFactory;
use App\Models\A2AChildTask;
use App\Models\A2ATask;
use App\Models\AgentRun;
use App\Models\AgentToolCall;
use Illuminate\Support\Str;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

class A2ASmokeCommand extends Command
{
    protected $signature = 'a2a:smoke
                            {--agent=runtime_assistant : Agent slug}
                            {--subagent=docs_assistant : Subagent slug}
                            {--prompt=Use the remote_a2a_agent tool to ask the docs_assistant subagent to call topic_selector_assistant, choose a topic, and then return a short funny response using that topic. : User prompt}
                            {--fail-subagent-once : Inject one transient failure into the configured subagent}
                            {--fail-agent-once= : Agent slug that should fail once during this smoke run}
                            {--guard-limits : Run deterministic invocation guard checks without calling an LLM}
                            {--timeout=15 : Seconds to wait for an external queue worker}';

    protected $description = 'Smoke-test A2A async task processing and local subagent resume flow';

    public function handle(
        SendMessageAction $sendMessage,
        TaskPayloadFactory $payloads,
        RuntimeAgentTaskRepository $tasks,
    ): int {
        if ((bool) $this->option('guard-limits')) {
            return $this->smokeInvocationGuard();
        }

        $agent = (string) $this->option('agent');
        $subagent = (string) $this->option('subagent');
        $prompt = (string) $this->option('prompt');
        $failAgent = (string) $this->option('fail-agent-once');

        if ($failAgent === '' && (bool) $this->option('fail-subagent-once')) {
            $failAgent = $subagent;
        }

        if (! str_contains($prompt, $subagent)) {
            $prompt .= " Use subagent slug {$subagent}.";
        }

        $this->info('Creating A2A task for real runtime flow...');
        $metadata = ['smoke' => true];

        if ($failAgent !== '') {
            $metadata['smoke_fail_once_agent_slug'] = $failAgent;
            $this->warn("Smoke will inject one transient failure into agent [{$failAgent}].");
        }

        $task = $sendMessage->handle(
            agentSlug: $agent,
            message: $payloads->userMessage($prompt),
            metadata: $metadata,
        );

        $this->line("A2A task: {$task['id']} state={$task['status']['state']}");
        $run = AgentRun::query()->find($task['metadata']['agent_run_id'] ?? null);
        $childTask = null;

        $this->line("Parent run: {$run->id} state={$run->state}");
        $this->line('Queued jobs: '.DB::table('jobs')->count());

        $this->info('Waiting for the queue worker to process async jobs...');

        $timeout = (int) $this->option('timeout');

        if ($failAgent !== '' && $timeout === 15) {
            $timeout = 45;
            $this->line('Using 45s timeout for injected-failure recovery smoke.');
        }

        $deadline = time() + $timeout;

        do {
            sleep(1);

            $task = $tasks->find($task['id']);
            $run = AgentRun::query()->find($run->id);
            $childTask = A2AChildTask::query()
                ->where('agent_run_id', $run?->id)
                ->latest()
                ->first();

            if (
                ($task['status']['state'] ?? null) === A2AState::COMPLETED->value
                && $run?->state === 'completed'
                && $childTask?->state === A2AState::COMPLETED
            ) {
                break;
            }
        } while (time() < $deadline);

        $this->newLine();
        $this->line("A2A task final state: {$task['status']['state']}");
        $this->line("Parent run final state: {$run?->state}");
        $this->line('Child A2A task final state: '.($childTask?->state?->value ?? 'not-created'));
        $remoteTask = $childTask instanceof A2AChildTask
            ? A2ATask::query()->find($childTask->remote_task_id)
            : null;

        if ($remoteTask instanceof A2ATask) {
            $this->line("Child remote task attempts: {$remoteTask->attempts}");
            $this->line('Child remote task last error: '.($remoteTask->last_error_kind ?? 'none'));
        }

        if (
            ($task['status']['state'] ?? null) !== A2AState::COMPLETED->value
            || $run?->state !== 'completed'
            || $childTask?->state !== A2AState::COMPLETED
        ) {
            $this->error('Smoke test did not complete.');
            $this->warn('Check that the Docker queue-worker service is running and has the latest code.');

            return SymfonyCommand::FAILURE;
        }

        $artifact = $task['artifacts'][0]['parts'][0]['text'] ?? '';
        $subagentArtifact = $run->output['tool_result']['artifact']['parts'][0]['text']
            ?? $run->output['tool_result']['result']['artifact']['parts'][0]['text']
            ?? '';

        $this->info('Smoke test completed.');
        $this->line("A2A artifact: {$artifact}");
        $this->line("Subagent artifact: {$subagentArtifact}");

        return SymfonyCommand::SUCCESS;
    }

    private function smokeInvocationGuard(): int
    {
        $this->info('Running deterministic A2A invocation guard smoke checks...');

        $previousLimits = config('runtime-agents.invocation_limits');

        try {
            $this->assertCycleIsRejected();
            $this->assertDepthIsRejected();
            $this->assertChildrenPerRunIsRejected();
            $this->assertTotalTreeBudgetIsRejected();
        } finally {
            config()->set('runtime-agents.invocation_limits', $previousLimits);
        }

        $this->info('Invocation guard smoke completed.');

        return SymfonyCommand::SUCCESS;
    }

    private function assertCycleIsRejected(): void
    {
        config()->set('runtime-agents.invocation_limits.max_depth', 5);
        config()->set('runtime-agents.invocation_limits.max_total_child_tasks', 25);
        config()->set('runtime-agents.invocation_limits.max_children_per_run', 5);
        config()->set('runtime-agents.invocation_limits.max_agent_revisits_per_path', 0);

        $run = $this->smokeRun('runtime_assistant');
        $invocation = app(A2AInvocationGuard::class)->rootInvocation('smoke-root', $run->id, 'runtime_assistant');
        $invocation = [
            ...$invocation,
            'depth' => 1,
            'path' => [
                ...$invocation['path'],
                [
                    'agent_slug' => 'docs_assistant',
                    'agent_run_id' => (string) Str::uuid(),
                ],
            ],
        ];

        $this->expectGuardRejection('agent_cycle', function () use ($run, $invocation): void {
            app(A2AInvocationGuard::class)->authorizeFromInvocation(
                parentInvocation: $invocation,
                parentTaskId: 'smoke-root',
                parentAgentRunId: $run->id,
                parentAgentSlug: 'docs_assistant',
                childAgentSlug: 'runtime_assistant',
            );
        });
    }

    private function assertDepthIsRejected(): void
    {
        config()->set('runtime-agents.invocation_limits.max_depth', 1);
        config()->set('runtime-agents.invocation_limits.max_total_child_tasks', 25);
        config()->set('runtime-agents.invocation_limits.max_children_per_run', 5);
        config()->set('runtime-agents.invocation_limits.max_agent_revisits_per_path', 0);

        $run = $this->smokeRun('runtime_assistant');
        $invocation = app(A2AInvocationGuard::class)->rootInvocation('smoke-depth', $run->id, 'runtime_assistant');
        $invocation = [
            ...$invocation,
            'depth' => 1,
            'path' => [
                ...$invocation['path'],
                [
                    'agent_slug' => 'docs_assistant',
                    'agent_run_id' => (string) Str::uuid(),
                ],
            ],
        ];

        $this->expectGuardRejection('max_depth', function () use ($run, $invocation): void {
            app(A2AInvocationGuard::class)->authorizeFromInvocation(
                parentInvocation: $invocation,
                parentTaskId: 'smoke-depth',
                parentAgentRunId: $run->id,
                parentAgentSlug: 'docs_assistant',
                childAgentSlug: 'topic_selector_assistant',
            );
        });
    }

    private function assertChildrenPerRunIsRejected(): void
    {
        config()->set('runtime-agents.invocation_limits.max_depth', 5);
        config()->set('runtime-agents.invocation_limits.max_total_child_tasks', 25);
        config()->set('runtime-agents.invocation_limits.max_children_per_run', 1);
        config()->set('runtime-agents.invocation_limits.max_agent_revisits_per_path', 0);

        $run = $this->smokeRun('runtime_assistant');
        $toolCall = $this->smokeToolCall($run);
        A2AChildTask::query()->create([
            'agent_run_id' => $run->id,
            'tool_call_id' => $toolCall->id,
            'remote_agent_slug' => 'docs_assistant',
            'remote_task_id' => (string) Str::uuid(),
            'remote_context_id' => (string) Str::uuid(),
            'state' => A2AState::SUBMITTED,
            'request_payload' => [],
        ]);

        $this->expectGuardRejection('max_children_per_run', function () use ($run): void {
            app(A2AInvocationGuard::class)->authorize(
                parentTaskId: null,
                parentAgentRunId: $run->id,
                parentAgentSlug: 'runtime_assistant',
                childAgentSlug: 'docs_assistant',
            );
        });
    }

    private function assertTotalTreeBudgetIsRejected(): void
    {
        config()->set('runtime-agents.invocation_limits.max_depth', 5);
        config()->set('runtime-agents.invocation_limits.max_total_child_tasks', 1);
        config()->set('runtime-agents.invocation_limits.max_children_per_run', 5);
        config()->set('runtime-agents.invocation_limits.max_agent_revisits_per_path', 0);

        $run = $this->smokeRun('runtime_assistant');
        $invocation = app(A2AInvocationGuard::class)->rootInvocation('smoke-total', $run->id, 'runtime_assistant');
        $childInvocation = [
            ...$invocation,
            'depth' => 1,
            'path' => [
                ...$invocation['path'],
                [
                    'agent_slug' => 'docs_assistant',
                    'agent_run_id' => (string) Str::uuid(),
                ],
            ],
        ];
        $toolCall = $this->smokeToolCall($run);
        A2AChildTask::query()->create([
            'agent_run_id' => $run->id,
            'tool_call_id' => $toolCall->id,
            'remote_agent_slug' => 'docs_assistant',
            'remote_task_id' => (string) Str::uuid(),
            'remote_context_id' => (string) Str::uuid(),
            'state' => A2AState::SUBMITTED,
            'request_payload' => [
                'invocation' => $childInvocation,
            ],
        ]);

        $this->expectGuardRejection('max_total_child_tasks', function () use ($run, $invocation): void {
            app(A2AInvocationGuard::class)->authorizeFromInvocation(
                parentInvocation: $invocation,
                parentTaskId: 'smoke-total',
                parentAgentRunId: $run->id,
                parentAgentSlug: 'runtime_assistant',
                childAgentSlug: 'topic_selector_assistant',
            );
        });
    }

    private function expectGuardRejection(string $reason, callable $callback): void
    {
        try {
            $callback();
        } catch (A2AInvocationLimitExceeded $exception) {
            if ($exception->reason === $reason) {
                $this->line("Guard rejected {$reason}: ok");

                return;
            }

            throw $exception;
        }

        throw new \RuntimeException("Expected invocation guard rejection [{$reason}].");
    }

    private function smokeRun(string $agentSlug): AgentRun
    {
        return AgentRun::query()->create([
            'id' => (string) Str::uuid(),
            'agent_slug' => $agentSlug,
            'state' => 'submitted',
            'input' => ['smoke_guard' => true],
        ]);
    }

    private function smokeToolCall(AgentRun $run): AgentToolCall
    {
        return AgentToolCall::query()->create([
            'id' => (string) Str::uuid(),
            'agent_run_id' => $run->id,
            'tool_name' => 'remote_a2a_agent',
            'state' => 'waiting',
            'arguments' => ['smoke_guard' => true],
        ]);
    }
}
