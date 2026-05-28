<?php

namespace App\Console\Commands;

use App\A2A\RuntimeAgentTaskRepository;
use App\A2A\SendMessageAction;
use App\A2A\TaskPayloadFactory;
use App\Models\A2AChildTask;
use App\Models\AgentRun;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

class A2ASmokeCommand extends Command
{
    protected $signature = 'a2a:smoke
                            {--agent=runtime_assistant : Agent slug}
                            {--subagent=docs_assistant : Subagent slug}
                            {--prompt=Use the remote_a2a_agent tool to ask the docs_assistant subagent to say funny joke, then summarize its answer. : User prompt}
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

        if (! str_contains($prompt, $subagent)) {
            $prompt .= " Use subagent slug {$subagent}.";
        }

        $this->info('Creating A2A task for real runtime flow...');

        $task = $sendMessage->handle(
            agentSlug: $agent,
            message: $payloads->userMessage($prompt),
            metadata: ['smoke' => true],
        );

        $this->line("A2A task: {$task['id']} state={$task['status']['state']}");
        $run = AgentRun::query()->find($task['metadata']['agent_run_id'] ?? null);
        $childTask = null;

        $this->line("Parent run: {$run->id} state={$run->state}");
        $this->line('Queued jobs: '.DB::table('jobs')->count());

        $this->info('Waiting for the queue worker to process async jobs...');

        $deadline = time() + (int) $this->option('timeout');

        do {
            sleep(1);

            $task = $tasks->find($task['id']);
            $run = AgentRun::query()->find($run->id);
            $childTask = A2AChildTask::query()
                ->where('agent_run_id', $run?->id)
                ->latest()
                ->first();

            if (
                ($task['status']['state'] ?? null) === 'COMPLETED'
                && $run?->state === 'completed'
                && $childTask?->state === 'COMPLETED'
            ) {
                break;
            }
        } while (time() < $deadline);

        $this->newLine();
        $this->line("A2A task final state: {$task['status']['state']}");
        $this->line("Parent run final state: {$run?->state}");
        $this->line('Child A2A task final state: '.($childTask?->state ?? 'not-created'));

        if (
            ($task['status']['state'] ?? null) !== 'COMPLETED'
            || $run?->state !== 'completed'
            || $childTask?->state !== 'COMPLETED'
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
