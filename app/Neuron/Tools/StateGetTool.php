<?php

namespace App\Neuron\Tools;

use App\Neuron\RuntimeAgentContext;
use App\Neuron\State\AgentStateStore;
use App\Neuron\Tools\Concerns\ReturnsJsonToolResponses;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Throwable;

class StateGetTool extends Tool
{
    use ReturnsJsonToolResponses;

    public function __construct(
        private readonly RuntimeAgentContext $context,
        private readonly AgentStateStore $store,
    ) {
        parent::__construct(
            name: 'state_get',
            description: 'Get full details and content for a visible state entity by ID.',
        );
    }

    protected function properties(): array
    {
        return [
            ToolProperty::make(
                name: 'id',
                type: PropertyType::STRING,
                description: 'State entity ID returned by state_create or state_list.',
                required: true,
            ),
        ];
    }

    public function __invoke(string $id): string
    {
        try {
            return $this->success($this->store->get($this->context, $id));
        } catch (Throwable $exception) {
            return $this->failure($exception);
        }
    }
}
