<?php

namespace App\Neuron\Agents;

use App\Models\AgentChatMessage;
use App\Neuron\Nodes\RemoteA2AToolNode;
use App\Neuron\RuntimeAgentContext;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Nodes\ParallelToolNode;
use NeuronAI\Agent\SystemPrompt;
use NeuronAI\Chat\History\EloquentChatHistory;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\Persistence\PersistenceInterface;

class ConfigurableRuntimeAgent extends Agent
{
    /**
     * @param  ToolInterface[]  $configuredTools
     */
    public function __construct(
        private readonly AIProviderInterface $configuredProvider,
        private readonly array $definition,
        private readonly ?RuntimeAgentContext $context = null,
        private readonly array $configuredTools = [],
        ?PersistenceInterface $persistence = null,
    ) {
        parent::__construct(
            persistence: $persistence,
            resumeToken: $context?->resumeToken,
        );
    }

    protected function provider(): AIProviderInterface
    {
        return $this->configuredProvider;
    }

    protected function instructions(): string
    {
        $instructions = $this->definition['instructions'] ?? [];
        $background = $instructions['background'] ?? [];
        $subagents = $this->definition['available_subagent_cards'] ?? [];

        if ($subagents !== []) {
            $allowedSubagentSlugs = collect($subagents)
                ->pluck('slug')
                ->filter()
                ->values()
                ->all();

            $background[] = 'Allowed subagent slugs: '.implode(', ', $allowedSubagentSlugs).'. Only use these exact slugs with get_agent_card or remote_a2a_agent.';
            $background[] = 'Available subagents are summarized below. Use get_agent_card for full details before delegating if the summary is not enough.';
            $background[] = json_encode($subagents, JSON_THROW_ON_ERROR);
        }

        return (string) new SystemPrompt(
            background: $background,
            steps: $instructions['steps'] ?? [],
            output: $instructions['output'] ?? [],
        );
    }

    protected function state(): AgentState
    {
        $state = new AgentState;

        if ($this->context instanceof RuntimeAgentContext) {
            $state->setChatHistory(new EloquentChatHistory(
                threadId: $this->context->historyThreadId(),
                modelClass: AgentChatMessage::class,
                contextWindow: $this->definition['history_context_window'] ?? 50000,
            ));
        }

        return $state;
    }

    protected function tools(): array
    {
        return $this->configuredTools;
    }

    protected function compose(array|Node $nodes): void
    {
        if ($this->eventNodeMap !== []) {
            return;
        }

        $nodes = is_array($nodes) ? $nodes : [$nodes];
        $toolNode = $this->parallelToolCalls
            ? new ParallelToolNode($this->toolMaxRuns, $this->resolveToolErrorHandler())
            : new RemoteA2AToolNode($this->toolMaxRuns, $this->resolveToolErrorHandler());

        $this->addNodes([
            ...$nodes,
            $toolNode,
        ]);
    }
}
