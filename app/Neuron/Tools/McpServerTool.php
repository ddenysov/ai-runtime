<?php

namespace App\Neuron\Tools;

use App\Mcp\Models\McpServer;
use App\Mcp\Services\McpStdioToolExecutor;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolPropertyInterface;

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
     * @return array<int, ToolPropertyInterface>
     */
    protected function properties(): array
    {
        $schema = $this->config['input_schema'] ?? [];
        if (! is_array($schema)) {
            return [];
        }

        return app(McpInputSchemaPropertyMapper::class)->mapProperties($schema);
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
}
