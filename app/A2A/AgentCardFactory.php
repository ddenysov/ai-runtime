<?php

namespace App\A2A;

use App\Neuron\RuntimeAgentDefinitionRepository;
use Illuminate\Support\Arr;
use InvalidArgumentException;

class AgentCardFactory
{
    public function __construct(
        private readonly RuntimeAgentDefinitionRepository $definitions,
    ) {}

    public function make(string $agentSlug): array
    {
        try {
            $definition = $this->definitions->require($agentSlug);
        } catch (InvalidArgumentException) {
            abort(404, "Runtime agent [{$agentSlug}] is not configured.");
        }

        return [
            'name' => $definition['name'] ?? $agentSlug,
            'description' => $definition['description'] ?? 'A2A-compatible Laravel runtime agent.',
            'supportedInterfaces' => [
                [
                    'url' => url("/api/a2a/{$agentSlug}"),
                    'protocolBinding' => 'JSONRPC',
                    'protocolVersion' => '1.0',
                ],
            ],
            'provider' => [
                'organization' => config('app.name'),
                'url' => config('app.url'),
            ],
            'version' => '1.0.0',
            'capabilities' => [
                'streaming' => false,
                'pushNotifications' => true,
                'extendedAgentCard' => false,
            ],
            'securitySchemes' => [
                'bearer' => [
                    'httpAuthSecurityScheme' => [
                        'scheme' => 'bearer',
                        'bearerFormat' => 'opaque',
                    ],
                ],
            ],
            'securityRequirements' => [
                ['bearer' => []],
            ],
            'defaultInputModes' => $definition['input_modes'] ?? ['text/plain'],
            'defaultOutputModes' => $definition['output_modes'] ?? ['text/plain'],
            'skills' => collect($definition['skills'] ?? [])->map(function (array $skill) use ($definition): array {
                return [
                    'id' => $skill['id'],
                    'name' => $skill['name'],
                    'description' => $skill['description'] ?? Arr::get($definition, 'description'),
                    'tags' => $skill['tags'] ?? [],
                    'examples' => $skill['examples'] ?? [],
                    'inputModes' => $definition['input_modes'] ?? ['text/plain'],
                    'outputModes' => $definition['output_modes'] ?? ['text/plain'],
                ];
            })->all(),
        ];
    }
}
