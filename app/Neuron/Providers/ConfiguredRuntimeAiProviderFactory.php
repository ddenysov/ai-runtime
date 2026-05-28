<?php

namespace App\Neuron\Providers;

use InvalidArgumentException;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Gemini\Gemini;
use NeuronAI\Providers\OpenAI\OpenAI;

class ConfiguredRuntimeAiProviderFactory implements RuntimeAiProviderFactory
{
    public function make(array $definition): AIProviderInterface
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
}
