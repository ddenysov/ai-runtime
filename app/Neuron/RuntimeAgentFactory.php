<?php

namespace App\Neuron;

use App\A2A\AgentCardFactory;
use App\Mcp\Models\McpServer;
use App\Mcp\Services\McpStdioToolExecutor;
use App\Neuron\Agents\ConfigurableRuntimeAgent;
use App\Neuron\Persistence\LaravelWorkflowPersistence;
use App\Neuron\Providers\RuntimeAiProviderFactory;
use App\Neuron\Diary\DiaryService;
use App\Neuron\State\AgentStateStore;
use App\Neuron\Tools\DiaryReadTool;
use App\Neuron\Tools\DiaryWriteTool;
use App\Neuron\Tools\GetAgentCardTool;
use App\Neuron\Tools\McpServerTool;
use App\Neuron\Tools\RemoteA2AAgentTool;
use App\Neuron\Tools\RollDiceTool;
use App\Neuron\Tools\StateCreateTool;
use App\Neuron\Tools\StateDeleteTool;
use App\Neuron\Tools\StateGetTool;
use App\Neuron\Tools\StateListTool;
use App\Neuron\Tools\StateUpdateTool;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use NeuronAI\Agent\Agent;

class RuntimeAgentFactory
{
    public function __construct(
        private readonly AgentCardFactory $agentCards,
        private readonly RuntimeAiProviderFactory $providers,
        private readonly RuntimeAgentDefinitionRepository $definitions,
    ) {}

    public function make(?string $slug = null, ?RuntimeAgentContext $context = null): Agent
    {
        $slug ??= config('runtime-agents.default');
        $definition = $this->definitions->require($slug);

        $definition['available_subagent_cards'] = $this->summarizeSubagents($definition);

        return new ConfigurableRuntimeAgent(
            configuredProvider: $this->providers->make($definition),
            definition: Arr::add($definition, 'slug', $slug),
            context: $context,
            configuredTools: $this->tools($definition, $context),
            persistence: new LaravelWorkflowPersistence,
        );
    }

    private function tools(array $definition, ?RuntimeAgentContext $context): array
    {
        if (! $context instanceof RuntimeAgentContext) {
            return [];
        }

        $allowedSubagents = $definition['subagents'] ?? [];

        return collect($definition['tools'] ?? [])
            ->map(fn (mixed $tool): object => $this->makeTool($tool, $context, $allowedSubagents))
            ->all();
    }

    /**
     * @param  string[]  $allowedSubagents
     */
    private function makeTool(mixed $tool, RuntimeAgentContext $context, array $allowedSubagents): object
    {
        [$slug, $config] = $this->normalizeToolDefinition($tool);

        return match (true) {
            $slug === 'remote_a2a_agent' => new RemoteA2AAgentTool($context, $allowedSubagents),
            $slug === 'get_agent_card' => new GetAgentCardTool($allowedSubagents),
            $slug === 'roll_dice' => new RollDiceTool,
            $slug === 'state_create' => new StateCreateTool($context, app(AgentStateStore::class)),
            $slug === 'state_update' => new StateUpdateTool($context, app(AgentStateStore::class)),
            $slug === 'state_delete' => new StateDeleteTool($context, app(AgentStateStore::class)),
            $slug === 'state_list' => new StateListTool($context, app(AgentStateStore::class)),
            $slug === 'state_get' => new StateGetTool($context, app(AgentStateStore::class)),
            $slug === 'diary_write' => new DiaryWriteTool(app(DiaryService::class)),
            $slug === 'diary_read' => new DiaryReadTool(app(DiaryService::class)),
            str_starts_with($slug, 'mcp:') => $this->makeMcpTool($slug, $config),
            default => throw new InvalidArgumentException("Unsupported runtime tool [{$slug}]."),
        };
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function normalizeToolDefinition(mixed $tool): array
    {
        if (is_string($tool)) {
            return [$tool, []];
        }

        if (is_array($tool) && is_string($tool['slug'] ?? null)) {
            $config = $tool['config'] ?? [];

            return [$tool['slug'], is_array($config) ? $config : []];
        }

        throw new InvalidArgumentException('Invalid runtime tool definition.');
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function makeMcpTool(string $slug, array $config): McpServerTool
    {
        $serverUuid = $config['server_uuid'] ?? null;
        $toolName = $config['tool_name'] ?? null;

        if (! is_string($serverUuid) || ! is_string($toolName)) {
            throw new InvalidArgumentException("MCP runtime tool [{$slug}] is missing server_uuid or tool_name.");
        }

        $server = McpServer::query()
            ->where('uuid', $serverUuid)
            ->where('enabled', true)
            ->first();

        if (! $server instanceof McpServer) {
            throw new InvalidArgumentException("MCP server [{$serverUuid}] is not available.");
        }

        return new McpServerTool(
            server: $server,
            mcpToolName: $toolName,
            config: $config,
            executor: app(McpStdioToolExecutor::class),
        );
    }

    private function summarizeSubagents(array $definition): array
    {
        return collect($definition['subagents'] ?? [])
            ->map(function (string $slug): array {
                $card = $this->agentCards->make($slug);

                return [
                    'slug' => $slug,
                    'name' => $card['name'] ?? $slug,
                    'description' => $card['description'] ?? null,
                    'skills' => collect($card['skills'] ?? [])
                        ->map(fn (array $skill): array => [
                            'id' => $skill['id'] ?? null,
                            'name' => $skill['name'] ?? null,
                            'description' => $skill['description'] ?? null,
                            'tags' => $skill['tags'] ?? [],
                        ])
                        ->all(),
                ];
            })
            ->all();
    }
}
