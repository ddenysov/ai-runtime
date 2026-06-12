<?php

namespace Tests\Feature;

use App\Neuron\Providers\EchoProvider;
use App\Neuron\Providers\RuntimeAiProviderFactory;
use App\Neuron\RuntimeAgentContext;
use App\Neuron\RuntimeAgentFactory;
use App\Neuron\Tools\DiaryReadTool;
use App\Neuron\Tools\DiaryWriteTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use NeuronAI\Providers\AIProviderInterface;
use ReflectionMethod;
use Tests\TestCase;

class DiaryToolRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_runtime_factory_registers_diary_tools(): void
    {
        $this->bindProvider(new EchoProvider);

        config([
            'runtime-agents.agents.diary_test' => [
                'name' => 'Diary Tester',
                'description' => 'Tests diary tools.',
                'tools' => ['diary_write', 'diary_read'],
                'subagents' => [],
                'instructions' => [
                    'background' => ['Keep a personal diary.'],
                ],
            ],
        ]);

        $agent = app(RuntimeAgentFactory::class)->make(
            'diary_test',
            new RuntimeAgentContext('diary_test', 'test-run'),
        );

        $tools = (new ReflectionMethod($agent, 'tools'))->invoke($agent);
        $toolNames = array_map(
            static fn (object $tool): string => $tool->getName(),
            $tools,
        );

        $this->assertContains('diary_write', $toolNames);
        $this->assertContains('diary_read', $toolNames);

        $this->assertInstanceOf(
            DiaryWriteTool::class,
            collect($tools)->first(static fn (object $tool): bool => $tool->getName() === 'diary_write'),
        );

        $this->assertInstanceOf(
            DiaryReadTool::class,
            collect($tools)->first(static fn (object $tool): bool => $tool->getName() === 'diary_read'),
        );
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
