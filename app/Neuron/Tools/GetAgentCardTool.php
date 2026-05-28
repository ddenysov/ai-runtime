<?php

namespace App\Neuron\Tools;

use App\A2A\AgentCardFactory;
use InvalidArgumentException;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

class GetAgentCardTool extends Tool
{
    /**
     * @param  string[]  $allowedSubagents
     */
    public function __construct(
        private readonly array $allowedSubagents,
    ) {
        parent::__construct(
            name: 'get_agent_card',
            description: 'Get the full A2A Agent Card for an allowed subagent by slug.',
        );
    }

    protected function properties(): array
    {
        return [
            ToolProperty::make(
                name: 'agent_slug',
                type: PropertyType::STRING,
                description: 'The slug of an allowed subagent.',
                required: true,
                enum: $this->allowedSubagents,
            ),
        ];
    }

    public function __invoke(string $agent_slug): string
    {
        if (! in_array($agent_slug, $this->allowedSubagents, true)) {
            throw new InvalidArgumentException("Subagent [{$agent_slug}] is not allowed.");
        }

        return json_encode(
            app(AgentCardFactory::class)->make($agent_slug),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );
    }
}
