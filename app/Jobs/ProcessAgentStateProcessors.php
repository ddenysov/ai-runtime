<?php

namespace App\Jobs;

use App\Models\AgentRun;
use App\Neuron\RuntimeAgentContext;
use App\Neuron\RuntimeAgentDefinitionRepository;
use App\Neuron\State\AgentStateProcessorRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessAgentStateProcessors implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public string $agentRunId,
        public string $userMessage,
        public string $assistantMessage,
    ) {}

    public function handle(
        RuntimeAgentDefinitionRepository $definitions,
        AgentStateProcessorRunner $runner,
    ): void {
        $run = AgentRun::query()->find($this->agentRunId);

        if (! $run instanceof AgentRun) {
            return;
        }

        $contextId = $run->input['context_id'] ?? null;
        $definition = $definitions->require($run->agent_slug);
        $assignments = $definition['state_processors'] ?? [];

        if (! is_array($assignments) || $assignments === []) {
            return;
        }

        $runner->run(
            context: new RuntimeAgentContext(
                agentSlug: $run->agent_slug,
                agentRunId: $run->id,
                conversationId: is_string($contextId) ? $contextId : null,
            ),
            assignments: $assignments,
            userMessage: $this->userMessage,
            assistantMessage: $this->assistantMessage,
        );
    }
}
