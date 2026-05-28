<?php

namespace App\Neuron\Providers;

use NeuronAI\Providers\AIProviderInterface;

interface RuntimeAiProviderFactory
{
    public function make(array $definition): AIProviderInterface;
}
