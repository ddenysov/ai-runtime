<?php

namespace App\Console\Commands;

use App\A2A\LocalSubAgentRunner;
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
                            {--prompt=Say hello from A2A async smoke test : User prompt}
                            {--timeout=15 : Seconds to wait for an external queue worker}';

    protected $description = 'Smoke-test A2A async task processing and local subagent resume flow';

    public function handle(
        SendMessageAction $sendMessage,
        TaskPayloadFactory $payloads,
        RuntimeAgentTaskRepository $tasks,
        LocalSubAgentRunner $subAgents,
    ): int {
        $agent = (string) $this->option('agent');
        $subagent = (string) $this->option('subagent');
        $prompt = (string) $this->option('prompt');

        $this->info('Creating A2A task...');

        $task = $sendMessage->handle(
            agentSlug: $agent,
            message: $payloads->userMessage($prompt),
            metadata: ['smoke' => true],
        );

        $this->line("A2A task: {$task['id']} state={$task['status']['state']}");

        $this->info('Creating parent run with local A2A subagent call...');

        $subagentFlow = $subAgents->start($agent, $subagent, "Subagent check: {$prompt}");
        $run = $subagentFlow['run'];
        $childTask = $subagentFlow['child_task'];

        $this->line("Parent run: {$run->id} state={$run->state}");
        $this->line("Child A2A task: {$childTask->remote_task_id} state={$childTask->state}");
        $this->line('Queued jobs: '.DB::table('jobs')->count());

        $this->info('Waiting for the queue worker to process async jobs...');

        $deadline = time() + (int) $this->option('timeout');

        do {
            sleep(1);

            $task = $tasks->find($task['id']);
            $run = AgentRun::query()->find($run->id);
            $childTask = A2AChildTask::query()->find($childTask->id);

            if (($task['status']['state'] ?? null) === 'COMPLETED' && $run?->state === 'completed' && $childTask?->state === 'COMPLETED') {
                break;
            }
        } while (time() < $deadline);

        $this->newLine();
        $this->line("A2A task final state: {$task['status']['state']}");
        $this->line("Parent run final state: {$run?->state}");
        $this->line("Child A2A task final state: {$childTask?->state}");

        if (($task['status']['state'] ?? null) !== 'COMPLETED' || $run?->state !== 'completed' || $childTask?->state !== 'COMPLETED') {
            $this->error('Smoke test did not complete.');
            $this->warn('Check that the Docker queue-worker service is running and has the latest code.');

            return SymfonyCommand::FAILURE;
        }

        $artifact = $task['artifacts'][0]['parts'][0]['text'] ?? '';
        $subagentArtifact = $run->output['tool_result']['artifact']['parts'][0]['text'] ?? '';

        $this->info('Smoke test completed.');
        $this->line("A2A artifact: {$artifact}");
        $this->line("Subagent artifact: {$subagentArtifact}");

        return SymfonyCommand::SUCCESS;
    }
}
