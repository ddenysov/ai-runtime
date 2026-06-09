<?php

namespace App\Neuron\Tools;

use App\Neuron\RuntimeAgentContext;
use App\Neuron\State\AgentStateStore;
use App\Neuron\Tools\Concerns\ReturnsJsonToolResponses;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Throwable;

class StateCreateTool extends Tool
{
    use ReturnsJsonToolResponses;

    public function __construct(
        private readonly RuntimeAgentContext $context,
        private readonly AgentStateStore $store,
    ) {
        parent::__construct(
            name: 'state_create',
            description: 'Create a persistent state entity for this agent. Use conversation scope for chat-local state and global scope for shared state.',
        );
    }

    protected function properties(): array
    {
        return [
            ToolProperty::make(
                name: 'title',
                type: PropertyType::STRING,
                description: 'Short human-readable title for lists, such as an NPC name.',
                required: true,
            ),
            ToolProperty::make(
                name: 'content',
                type: PropertyType::OBJECT,
                description: 'Arbitrary JSON object to store. For plain text, pass {"text": "..."}',
                required: true,
            ),
            ToolProperty::make(
                name: 'scope',
                type: PropertyType::STRING,
                description: 'State visibility: conversation for the current chat, or global for all agents with this tool. Defaults to conversation.',
                required: false,
            ),
            ToolProperty::make(
                name: 'entity_type',
                type: PropertyType::STRING,
                description: 'Optional entity category such as npc, location, note, quest, or plain_text.',
                required: false,
            ),
            ToolProperty::make(
                name: 'group',
                type: PropertyType::STRING,
                description: 'Optional logical folder or group name.',
                required: false,
            ),
            ArrayProperty::make(
                name: 'tags',
                description: 'Optional list of tag names.',
                required: false,
            ),
            ToolProperty::make(
                name: 'summary',
                type: PropertyType::STRING,
                description: 'Optional short summary shown in state lists.',
                required: false,
            ),
        ];
    }

    public function __invoke(
        string $title,
        array $content,
        ?string $scope = null,
        ?string $entity_type = null,
        ?string $group = null,
        ?array $tags = null,
        ?string $summary = null,
    ): string {
        try {
            return $this->success($this->store->create(
                context: $this->context,
                scope: $scope,
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
