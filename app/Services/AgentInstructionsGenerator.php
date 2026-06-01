<?php

namespace App\Services;

use App\Models\Agent;
use App\Neuron\RuntimeAgentFactory;
use App\Support\AgentInstructionsResponseParser;
use App\Support\AppSettings;
use NeuronAI\Chat\Messages\UserMessage;
use RuntimeException;

class AgentInstructionsGenerator
{
    public function __construct(
        private readonly AppSettings $settings,
        private readonly RuntimeAgentFactory $agentFactory,
        private readonly AgentInstructionsResponseParser $parser,
    ) {}

    /**
     * @param  array{background?: list<string>, steps?: list<string>, output?: list<string>}|null  $draftInstructions
     * @return array{
     *     instructions: array{background: list<string>, steps: list<string>, output: list<string>},
     *     raw_response: string,
     *     generator_agent: array{id: int, slug: string, name: string}
     * }
     */
    public function generate(
        Agent $target,
        ?string $brief = null,
        ?string $feedback = null,
        ?array $draftInstructions = null,
    ): array {
        $generator = $this->resolveGeneratorAgent();
        $target->loadMissing(['providerModel.provider', 'tools']);

        $prompt = $this->buildPrompt($target, $brief, $feedback, $draftInstructions);
        $response = $this->agentFactory
            ->make($generator->slug)
            ->chat(new UserMessage($prompt))
            ->getMessage()
            ->getContent() ?? '';

        $instructions = $this->parser->parse($response);

        if ($instructions['background'] === []) {
            throw new RuntimeException('The prompt generator did not return usable background instructions.');
        }

        return [
            'instructions' => $instructions,
            'raw_response' => $response,
            'generator_agent' => [
                'id' => $generator->id,
                'slug' => $generator->slug,
                'name' => $generator->name,
            ],
        ];
    }

    private function resolveGeneratorAgent(): Agent
    {
        $generatorAgentId = $this->settings->promptGeneratorAgentId();

        if ($generatorAgentId === null) {
            throw new RuntimeException('No prompt generator agent is configured in Settings.');
        }

        $generator = Agent::query()->find($generatorAgentId);

        if (! $generator instanceof Agent) {
            throw new RuntimeException('The configured prompt generator agent was not found.');
        }

        if (! $generator->is_active) {
            throw new RuntimeException('The configured prompt generator agent is inactive.');
        }

        return $generator;
    }

    /**
     * @param  array{background?: list<string>, steps?: list<string>, output?: list<string>}|null  $draftInstructions
     */
    private function buildPrompt(
        Agent $target,
        ?string $brief,
        ?string $feedback,
        ?array $draftInstructions,
    ): string {
        $enabledTools = $target->tools
            ->where('is_enabled', true)
            ->pluck('slug')
            ->values()
            ->all();

        $sections = [
            'You are generating operating instructions for a runtime AI agent.',
            '',
            'Target agent profile:',
            '- Name: '.$target->name,
            '- Slug: '.$target->slug,
            '- Description: '.($target->description ?: 'Not provided'),
            '- Provider model: '.($target->providerModel
                ? "{$target->providerModel->provider?->name} / {$target->providerModel->name}"
                : 'Not assigned'),
            '- Enabled tools: '.($enabledTools !== [] ? implode(', ', $enabledTools) : 'None'),
            '- Current instructions: '.json_encode($target->instructions ?? [], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ];

        if ($brief !== null && trim($brief) !== '') {
            $sections[] = '';
            $sections[] = 'Operator brief:';
            $sections[] = trim($brief);
        }

        if ($draftInstructions !== null) {
            $sections[] = '';
            $sections[] = 'Current draft to refine:';
            $sections[] = json_encode($draftInstructions, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }

        if ($feedback !== null && trim($feedback) !== '') {
            $sections[] = '';
            $sections[] = 'Refinement feedback:';
            $sections[] = trim($feedback);
        }

        $sections[] = '';
        $sections[] = 'Return ONLY valid JSON with this exact shape:';
        $sections[] = '{"background":["..."],"steps":["..."],"output":["..."]}';
        $sections[] = 'Each array item is one instruction line. Background must contain at least one item.';

        return implode("\n", $sections);
    }
}
