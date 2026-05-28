<?php

namespace App\Console\Commands;

use App\A2A\A2AState;
use App\A2A\RuntimeAgentTaskRepository;
use App\A2A\SendMessageAction;
use App\A2A\TaskPayloadFactory;
use App\Models\A2AChildTask;
use App\Models\A2ATask;
use App\Models\AgentRun;
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
                            {--timeout=15 : Seconds to wait for an external queue worker}';

    protected $description = 'Smoke-test A2A async task processing and local subagent resume flow';

    public function handle(
        SendMessageAction $sendMessage,
        TaskPayloadFactory $payloads,
        RuntimeAgentTaskRepository $tasks,
    ): int {
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
}
