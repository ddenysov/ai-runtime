<?php

namespace App\Neuron;

use App\Neuron\Agents\ConfigurableRuntimeAgent;
use App\Neuron\Providers\EchoProvider;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use NeuronAI\Agent\Agent;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\OpenAI\OpenAI;

class RuntimeAgentFactory
{
    public function make(?string $slug = null): Agent
    {
        $slug ??= config('runtime-agents.default');
        $definition = config("runtime-agents.agents.{$slug}");

        if (! is_array($definition)) {
            throw new InvalidArgumentException("Runtime agent [{$slug}] is not configured.");
        }

        return new ConfigurableRuntimeAgent(
            configuredProvider: $this->provider($definition),
            definition: Arr::add($definition, 'slug', $slug),
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
            default => throw new InvalidArgumentException('Unsupported runtime agent provider.'),
        };
    }
}
