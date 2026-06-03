<?php

namespace App\Neuron\Tools;

use App\Neuron\RuntimeAgentContext;
use App\Neuron\State\AgentStateStore;
use App\Neuron\Tools\Concerns\ReturnsJsonToolResponses;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Throwable;

class StateListTool extends Tool
{
    use ReturnsJsonToolResponses;

    public function __construct(
        private readonly RuntimeAgentContext $context,
        private readonly AgentStateStore $store,
    ) {
        parent::__construct(
            name: 'state_list',
            description: 'List visible state entities with optional filters. Results are compact; use state_get for full content.',
        );
    }

    protected function properties(): array
    {
        return [
            ToolProperty::make(
                name: 'scope',
                type: PropertyType::STRING,
                description: 'Optional filter: conversation or global. Omit to include global and current conversation state.',
                required: false,
            ),
            ToolProperty::make(
                name: 'group',
                type: PropertyType::STRING,
                description: 'Optional group name or slug filter.',
                required: false,
            ),
            ToolProperty::make(
                name: 'tag',
                type: PropertyType::STRING,
                description: 'Optional tag name or slug filter.',
                required: false,
            ),
            ToolProperty::make(
                name: 'entity_type',
                type: PropertyType::STRING,
                description: 'Optional entity category filter such as npc, location, note, quest, or plain_text.',
                required: false,
            ),
            ToolProperty::make(
                name: 'search',
                type: PropertyType::STRING,
                description: 'Optional text search over title, summary, and entity_type.',
                required: false,
            ),
            ToolProperty::make(
                name: 'limit',
                type: PropertyType::INTEGER,
                description: 'Maximum entries to return, from 1 to 50. Defaults to 20.',
                required: false,
            ),
        ];
    }

    public function __invoke(
        ?string $scope = null,
        ?string $group = null,
        ?string $tag = null,
        ?string $entity_type = null,
        ?string $search = null,
        ?int $limit = null,
    ): string {
        try {
            return $this->success($this->store->list(
                context: $this->context,
                scope: $scope,
                group: $group,
                tag: $tag,
                entityType: $entity_type,
                search: $search,
                limit: $limit,
            ));
        } catch (Throwable $exception) {
            return $this->failure($exception);
        }
    }
}
