<?php

namespace App\Neuron\Agents;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\SystemPrompt;
use NeuronAI\Providers\AIProviderInterface;

class ConfigurableRuntimeAgent extends Agent
{
    public function __construct(
        private readonly AIProviderInterface $configuredProvider,
        private readonly array $definition,
    ) {
        parent::__construct();
    }

    protected function provider(): AIProviderInterface
    {
        return $this->configuredProvider;
    }

    protected function instructions(): string
    {
        $instructions = $this->definition['instructions'] ?? [];

        return (string) new SystemPrompt(
            background: $instructions['background'] ?? [],
            steps: $instructions['steps'] ?? [],
            output: $instructions['output'] ?? [],
        );
    }
}
