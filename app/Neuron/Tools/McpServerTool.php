<?php

namespace App\Neuron\Tools;

use App\Mcp\Models\McpServer;
use App\Mcp\Services\McpStdioToolExecutor;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

class McpServerTool extends Tool
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly McpServer $server,
        private readonly string $mcpToolName,
        private readonly array $config,
        private readonly McpStdioToolExecutor $executor,
    ) {
        parent::__construct(
            name: $this->runtimeToolName($server, $mcpToolName),
            description: $this->runtimeDescription($server, $mcpToolName, $config),
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    protected function properties(): array
    {
        $schema = $this->config['input_schema'] ?? [];
        if (! is_array($schema)) {
            return [];
        }

        $properties = $schema['properties'] ?? [];
        if (! is_array($properties)) {
            return [];
        }

        $required = $schema['required'] ?? [];
        $required = is_array($required) ? $required : [];

        $toolProperties = [];
        foreach ($properties as $name => $propertySchema) {
            if (! is_string($name) || ! is_array($propertySchema)) {
                continue;
            }

            $type = $this->propertyType($propertySchema['type'] ?? 'string');
            if (! $type instanceof PropertyType) {
                continue;
            }

            $enum = $propertySchema['enum'] ?? [];
            $toolProperties[] = ToolProperty::make(
                name: $name,
                type: $type,
                description: is_string($propertySchema['description'] ?? null)
                    ? $propertySchema['description']
                    : null,
                required: in_array($name, $required, true),
                enum: is_array($enum) ? array_values($enum) : [],
            );
        }

        return $toolProperties;
    }

    public function __invoke(mixed ...$arguments): string
    {
        return $this->executor->execute(
            server: $this->server,
            toolName: $this->mcpToolName,
            arguments: $arguments,
        );
    }

    private function runtimeToolName(McpServer $server, string $toolName): string
    {
        $safeName = strtolower((string) preg_replace('/[^A-Za-z0-9_]+/', '_', $toolName));
        $safeName = trim($safeName, '_') ?: 'tool';
        $prefix = 'mcp_'.substr((string) $server->uuid, 0, 8).'_';

        return substr($prefix.$safeName, 0, 64);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function runtimeDescription(McpServer $server, string $toolName, array $config): string
    {
        $description = $config['description'] ?? null;
        if (is_string($description) && trim($description) !== '') {
            return $description;
        }

        $title = $config['title'] ?? null;
        if (is_string($title) && trim($title) !== '') {
            return $title;
        }

        return "Call the {$toolName} MCP tool on {$server->name}.";
    }

    private function propertyType(mixed $type): ?PropertyType
    {
        if (is_array($type)) {
            $type = collect($type)
                ->first(fn (mixed $candidate): bool => is_string($candidate) && $candidate !== 'null');
        }

        if (! is_string($type)) {
            return PropertyType::STRING;
        }

        return match ($type) {
            'integer' => PropertyType::INTEGER,
            'number' => PropertyType::NUMBER,
            'boolean' => PropertyType::BOOLEAN,
            'array' => PropertyType::ARRAY,
            'object' => PropertyType::OBJECT,
            default => PropertyType::STRING,
        };
    }
}
