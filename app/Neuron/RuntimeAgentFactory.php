<?php

namespace App\Neuron;

use App\A2A\AgentCardFactory;
use App\Neuron\Agents\ConfigurableRuntimeAgent;
use App\Neuron\Persistence\LaravelWorkflowPersistence;
use App\Neuron\Providers\EchoProvider;
use App\Neuron\Tools\GetAgentCardTool;
use App\Neuron\Tools\RemoteA2AAgentTool;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use NeuronAI\Agent\Agent;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Gemini\Gemini;
use NeuronAI\Providers\OpenAI\OpenAI;

class RuntimeAgentFactory
{
    public function __construct(
        private readonly AgentCardFactory $agentCards,
    ) {}

    public function make(?string $slug = null, ?RuntimeAgentContext $context = null): Agent
    {
        $slug ??= config('runtime-agents.default');
        $definition = config("runtime-agents.agents.{$slug}");

        if (! is_array($definition)) {
            throw new InvalidArgumentException("Runtime agent [{$slug}] is not configured.");
        }

        $definition['available_subagent_cards'] = $this->summarizeSubagents($definition);

        return new ConfigurableRuntimeAgent(
            configuredProvider: $this->provider($definition),
            definition: Arr::add($definition, 'slug', $slug),
            context: $context,
            configuredTools: $this->tools($definition, $context),
            persistence: new LaravelWorkflowPersistence,
        );
    }

    private function provider(array $definition): AIProviderInterface
    {
        return match ($definition['provider'] ?? 'echo') {
            'echo' => new EchoProvider,
            'openai' => new OpenAI(
                key: (string) config('services.openai.key'),
                model: $definition['model'] ?? config('services.openai.model', 'gpt-4.1-mini'),
            ),
            'gemini' => new Gemini(
                key: (string) config('services.gemini.key'),
                model: $definition['model'] ?? config('services.gemini.model', 'gemini-2.5-flash'),
            ),
            default => throw new InvalidArgumentException('Unsupported runtime agent provider.'),
        };
    }

    private function tools(array $definition, ?RuntimeAgentContext $context): array
    {
        if (! $context instanceof RuntimeAgentContext) {
            return [];
        }

        $allowedSubagents = $definition['subagents'] ?? [];

        return collect($definition['tools'] ?? [])
            ->map(fn (string $tool): object => match ($tool) {
                'remote_a2a_agent' => new RemoteA2AAgentTool($context, $allowedSubagents),
                'get_agent_card' => new GetAgentCardTool($allowedSubagents),
                default => throw new InvalidArgumentException("Unsupported runtime tool [{$tool}]."),
            })
            ->all();
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
