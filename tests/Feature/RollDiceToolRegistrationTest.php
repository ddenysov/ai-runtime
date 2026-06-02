<?php

namespace Tests\Feature;

use App\Neuron\Providers\EchoProvider;
use App\Neuron\Providers\RuntimeAiProviderFactory;
use App\Neuron\RuntimeAgentContext;
use App\Neuron\RuntimeAgentFactory;
use App\Neuron\Tools\RollDiceTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use NeuronAI\Providers\AIProviderInterface;
use ReflectionMethod;
use Tests\TestCase;

class RollDiceToolRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_runtime_factory_registers_roll_dice_tool(): void
    {
        $this->bindProvider(new EchoProvider);

        config([
            'runtime-agents.agents.roll_dice_test' => [
                'name' => 'Dice Tester',
                'description' => 'Tests dice rolls.',
                'tools' => ['roll_dice'],
                'subagents' => [],
                'instructions' => [
                    'background' => ['Roll dice when asked.'],
                ],
            ],
        ]);

        $agent = app(RuntimeAgentFactory::class)->make(
            'roll_dice_test',
            new RuntimeAgentContext('roll_dice_test', 'test-run'),
        );

        $tools = (new ReflectionMethod($agent, 'tools'))->invoke($agent);

        $this->assertContains('roll_dice', array_map(
            static fn (object $tool): string => $tool->getName(),
            $tools,
        ));

        $rollDiceTool = collect($tools)->first(
            static fn (object $tool): bool => $tool->getName() === 'roll_dice',
        );

        $this->assertInstanceOf(RollDiceTool::class, $rollDiceTool);
    }

    private function bindProvider(AIProviderInterface $provider): void
    {
        $this->app->bind(
            RuntimeAiProviderFactory::class,
            fn () => new class($provider) implements RuntimeAiProviderFactory
            {
                public function __construct(private readonly AIProviderInterface $provider) {}

                public function make(array $definition): AIProviderInterface
                {
                    return $this->provider;
                }
            },
        );
    }
}
