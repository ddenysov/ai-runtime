<?php

namespace App\Neuron\Tools;

use App\Neuron\RuntimeAgentContext;
use App\Neuron\State\AgentStateStore;
use App\Neuron\Tools\Concerns\ReturnsJsonToolResponses;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Throwable;

class StateUpdateTool extends Tool
{
    use ReturnsJsonToolResponses;

    public function __construct(
        private readonly RuntimeAgentContext $context,
        private readonly AgentStateStore $store,
    ) {
        parent::__construct(
            name: 'state_update',
            description: 'Update an existing state entity by ID. Only visible global state or current conversation state can be updated.',
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
            ToolProperty::make(
                name: 'title',
                type: PropertyType::STRING,
                description: 'Optional replacement title.',
                required: false,
            ),
            ToolProperty::make(
                name: 'content',
                type: PropertyType::OBJECT,
                description: 'Optional replacement JSON content. For plain text, pass {"text": "..."}',
                required: false,
            ),
            ToolProperty::make(
                name: 'entity_type',
                type: PropertyType::STRING,
                description: 'Optional replacement entity category. Pass an empty string to clear it.',
                required: false,
            ),
            ToolProperty::make(
                name: 'group',
                type: PropertyType::STRING,
                description: 'Optional replacement group name. Pass an empty string to remove the group.',
                required: false,
            ),
            ToolProperty::make(
                name: 'tags',
                type: PropertyType::ARRAY,
                description: 'Optional replacement list of tag names. Pass an empty array to clear tags.',
                required: false,
            ),
            ToolProperty::make(
                name: 'summary',
                type: PropertyType::STRING,
                description: 'Optional replacement summary. Pass an empty string to clear it.',
                required: false,
            ),
        ];
    }

    public function __invoke(
        string $id,
        ?string $title = null,
        ?array $content = null,
        ?string $entity_type = null,
        ?string $group = null,
        ?array $tags = null,
        ?string $summary = null,
    ): string {
        try {
            return $this->success($this->store->update(
                context: $this->context,
                id: $id,
                title: $title,
                content: $content,
                entityType: $entity_type,
                group: $group,
                tags: $tags,
                summary: $summary,
            ));
        } catch (Throwable $exception) {
            return $this->failure($exception);
        }
    }
}
