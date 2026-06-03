<?php

namespace App\Neuron;

use App\Models\Agent;
use Illuminate\Support\Arr;
use InvalidArgumentException;

class RuntimeAgentDefinitionRepository
{
    public function find(string $slug): ?array
    {
        $agent = Agent::query()
            ->with(['providerModel.provider', 'tools', 'stateProcessorAssignments.processor.extractorAgent'])
            ->where('slug', $slug)
            ->first();

        if ($agent instanceof Agent) {
            if (! $agent->is_active) {
                throw new InvalidArgumentException("Runtime agent [{$slug}] is inactive.");
            }

            return $agent->toRuntimeDefinition();
        }

        $definition = config("runtime-agents.agents.{$slug}");

        if (! is_array($definition)) {
            return null;
        }

        return Arr::add($definition, 'slug', $slug);
    }

    public function require(string $slug): array
    {
        $definition = $this->find($slug);

        if ($definition === null) {
            throw new InvalidArgumentException("Runtime agent [{$slug}] is not configured.");
        }

        return $definition;
    }
}
