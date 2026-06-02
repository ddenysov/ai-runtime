<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\AgentChatMessage;
use App\Models\AgentRun;
use App\Models\AiProvider;
use App\Models\AiProviderModel;
use App\Neuron\Providers\EchoProvider;
use App\Neuron\Providers\RuntimeAiProviderFactory;
use App\Neuron\RuntimeAgentDefinitionRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use NeuronAI\Providers\AIProviderInterface;
use Tests\TestCase;

class AgentTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_agent_via_api(): void
    {
        $providerModel = $this->providerModel();

        $response = $this->postJson('/api/agents', [
            'slug' => 'runtime-assistant',
            'name' => 'Runtime Assistant',
            'description' => 'Answers runtime questions.',
            'ai_provider_model_id' => $providerModel->id,
            'instructions' => [
                'background' => ['You are a runtime assistant.'],
                'steps' => ['Understand the task.'],
                'output' => ['Return concise output.'],
            ],
            'input_modes' => ['text/plain'],
            'output_modes' => ['text/plain'],
            'subagents' => ['docs-assistant'],
            'tools' => [
                ['slug' => 'remote_a2a_agent', 'is_enabled' => true],
                ['slug' => 'get_agent_card', 'is_enabled' => false],
            ],
            'input_schema' => ['type' => 'object'],
            'output_schema' => ['type' => 'object'],
            'temperature' => 0.7,
            'max_tokens' => 8192,
            'timeout_seconds' => 180,
            'history_context_window' => 64000,
            'is_active' => true,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('slug', 'runtime-assistant')
            ->assertJsonPath('name', 'Runtime Assistant')
            ->assertJsonPath('provider_model.id', $providerModel->id)
            ->assertJsonFragment(['slug' => 'remote_a2a_agent'])
            ->assertJsonFragment(['version' => 1]);

        $this->assertDatabaseHas('agents', [
            'slug' => 'runtime-assistant',
            'ai_provider_model_id' => $providerModel->id,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('agent_tools', [
            'slug' => 'remote_a2a_agent',
            'is_enabled' => true,
        ]);
        $this->assertDatabaseHas('agent_versions', [
            'version' => 1,
        ]);
    }

    public function test_create_agent_api_validates_payload_and_active_provider_model(): void
    {
        $inactiveProviderModel = $this->providerModel(isActive: false);

        $response = $this->postJson('/api/agents', [
            'slug' => 'Invalid Slug',
            'name' => '',
            'ai_provider_model_id' => $inactiveProviderModel->id,
            'instructions' => [
                'background' => [],
            ],
            'tools' => [
                ['slug' => 'unsupported_tool'],
            ],
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'slug',
                'name',
                'ai_provider_model_id',
                'instructions.background',
                'tools.0.slug',
            ]);
    }

    public function test_can_list_agents_with_search_filters_sorting_and_pagination(): void
    {
        $providerModel = $this->providerModel();
        $inactive = Agent::query()->create([
            'slug' => 'draft-agent',
            'name' => 'Draft Agent',
            'ai_provider_model_id' => $providerModel->id,
            'instructions' => ['background' => ['Draft']],
            'is_active' => false,
        ]);
        $active = Agent::query()->create([
            'slug' => 'runtime-assistant',
            'name' => 'Runtime Assistant',
            'ai_provider_model_id' => $providerModel->id,
            'instructions' => ['background' => ['Runtime']],
            'is_active' => true,
        ]);
        $active->tools()->create(['slug' => 'remote_a2a_agent']);
        $active->createVersionSnapshot();

        $response = $this->getJson('/api/agents?'.http_build_query([
            'filter' => [
                'search' => 'runtime',
                'is_active' => true,
            ],
            'include' => 'providerModel.provider,toolsCount,versionsCount',
            'sort' => 'name',
            'page' => 1,
            'per_page' => 1,
        ]));

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'runtime-assistant')
            ->assertJsonPath('data.0.provider_model.id', $providerModel->id)
            ->assertJsonPath('data.0.tools_count', 1)
            ->assertJsonPath('data.0.versions_count', 1)
            ->assertJsonPath('total', 1);

        $this->assertTrue($inactive->exists);
    }

    public function test_can_show_agent_details_via_api(): void
    {
        $providerModel = $this->providerModel();
        $agent = Agent::query()->create([
            'slug' => 'runtime-assistant',
            'name' => 'Runtime Assistant',
            'ai_provider_model_id' => $providerModel->id,
            'instructions' => ['background' => ['Runtime']],
            'input_modes' => ['text/plain'],
            'output_modes' => ['application/json'],
            'is_active' => true,
        ]);
        $agent->tools()->create(['slug' => 'remote_a2a_agent', 'is_enabled' => true]);
        $agent->createVersionSnapshot();

        $response = $this->getJson("/api/agents/{$agent->id}");

        $response
            ->assertOk()
            ->assertJsonPath('slug', 'runtime-assistant')
            ->assertJsonPath('provider_model.id', $providerModel->id)
            ->assertJsonPath('provider_model.provider.id', $providerModel->provider->id)
            ->assertJsonPath('tools.0.slug', 'remote_a2a_agent')
            ->assertJsonPath('versions.0.version', 1);
    }

    public function test_can_update_agent_with_roll_dice_builtin_tool(): void
    {
        $providerModel = $this->providerModel();
        $agent = Agent::query()->create([
            'slug' => 'dice-master',
            'name' => 'Dice Master',
            'ai_provider_model_id' => $providerModel->id,
            'instructions' => ['background' => ['Roll dice when needed.']],
            'is_active' => true,
        ]);

        $response = $this->putJson("/api/agents/{$agent->id}", [
            'tools' => [
                ['slug' => 'roll_dice', 'is_enabled' => true],
            ],
        ]);

        $response
            ->assertOk()
            ->assertJsonFragment(['slug' => 'roll_dice']);

        $this->assertDatabaseHas('agent_tools', [
            'agent_id' => $agent->id,
            'slug' => 'roll_dice',
            'is_enabled' => true,
        ]);
    }

    public function test_can_start_agent_chat_run_via_api(): void
    {
        $this->bindProvider(new EchoProvider);
        $agent = Agent::query()->create([
            'slug' => 'chat-assistant',
            'name' => 'Chat Assistant',
            'ai_provider_model_id' => $this->providerModel()->id,
            'instructions' => ['background' => ['Answer chat prompts.']],
            'is_active' => true,
        ]);

        $response = $this->postJson("/api/agents/{$agent->id}/chat", [
            'message' => 'hello from chat',
        ]);

        $response
            ->assertAccepted()
            ->assertJsonStructure([
                'run_id',
                'task_id',
                'context_id',
                'stream_url',
                'snapshot' => [
                    'run' => ['id', 'state'],
                    'task' => ['id', 'state', 'artifact'],
                    'terminal',
                ],
            ])
            ->assertJsonPath('snapshot.run.state', 'completed')
            ->assertJsonPath('snapshot.task.state', 'COMPLETED')
            ->assertJsonPath('snapshot.terminal', true);

        $run = AgentRun::query()->findOrFail($response->json('run_id'));

        $this->assertSame('chat-assistant', $run->agent_slug);
        $this->assertStringContainsString('hello from chat', $run->output['message']);
    }

    public function test_agent_chat_reuses_context_history_across_runs(): void
    {
        $this->bindProvider(new EchoProvider);
        $agent = Agent::query()->create([
            'slug' => 'context-chat-assistant',
            'name' => 'Context Chat Assistant',
            'ai_provider_model_id' => $this->providerModel()->id,
            'instructions' => ['background' => ['Answer chat prompts.']],
            'is_active' => true,
        ]);
        $contextId = 'browser-chat-context';

        $firstResponse = $this->postJson("/api/agents/{$agent->id}/chat", [
            'message' => 'first chat message',
            'context_id' => $contextId,
        ]);
        $secondResponse = $this->postJson("/api/agents/{$agent->id}/chat", [
            'message' => 'second chat message',
            'context_id' => $contextId,
        ]);

        $firstResponse
            ->assertAccepted()
            ->assertJsonPath('context_id', $contextId);
        $secondResponse
            ->assertAccepted()
            ->assertJsonPath('context_id', $contextId);

        $firstRun = AgentRun::query()->findOrFail($firstResponse->json('run_id'));
        $secondRun = AgentRun::query()->findOrFail($secondResponse->json('run_id'));

        $this->assertNotSame($firstRun->id, $secondRun->id);
        $this->assertSame($contextId, $firstRun->input['context_id']);
        $this->assertSame($contextId, $secondRun->input['context_id']);
        $this->assertStringContainsString('first chat message', $secondRun->output['message']);
        $this->assertStringContainsString('second chat message', $secondRun->output['message']);
        $this->assertSame(1, AgentChatMessage::query()->distinct('thread_id')->count('thread_id'));
        $this->assertDatabaseHas('agent_chat_messages', [
            'thread_id' => "context-chat-assistant:{$contextId}",
            'role' => 'user',
        ]);
    }

    public function test_agent_chat_events_stream_completed_run_snapshot(): void
    {
        $this->bindProvider(new EchoProvider);
        $agent = Agent::query()->create([
            'slug' => 'stream-assistant',
            'name' => 'Stream Assistant',
            'ai_provider_model_id' => $this->providerModel()->id,
            'instructions' => ['background' => ['Answer stream prompts.']],
            'is_active' => true,
        ]);

        $chatResponse = $this->postJson("/api/agents/{$agent->id}/chat", [
            'message' => 'stream this answer',
        ]);

        $response = $this->get($chatResponse->json('stream_url'));
        $content = $response->streamedContent();

        $response
            ->assertOk()
            ->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8');
        $this->assertStringContainsString('data: ', $content);
        $this->assertStringContainsString('"state":"COMPLETED"', $content);
        $this->assertStringContainsString('stream this answer', $content);
    }

    public function test_runtime_definition_repository_resolves_active_database_agent_before_config(): void
    {
        $providerModel = $this->providerModel();
        $agent = Agent::query()->create([
            'slug' => 'runtime-assistant',
            'name' => 'Database Runtime Assistant',
            'ai_provider_model_id' => $providerModel->id,
            'instructions' => [
                'background' => ['Loaded from database.'],
            ],
            'input_modes' => ['text/plain'],
            'output_modes' => ['application/json'],
            'subagents' => ['docs-assistant'],
        ]);
        $agent->tools()->create(['slug' => 'get_agent_card']);

        $definition = app(RuntimeAgentDefinitionRepository::class)->require('runtime-assistant');

        $this->assertSame('Database Runtime Assistant', $definition['name']);
        $this->assertSame($providerModel->id, $definition['ai_provider_model_id']);
        $this->assertSame($providerModel->slug, $definition['ai_provider_model_slug']);
        $this->assertSame(['get_agent_card'], $definition['tools']);
        $this->assertSame(['application/json'], $definition['output_modes']);
    }

    public function test_runtime_definition_repository_rejects_inactive_database_agent(): void
    {
        $providerModel = $this->providerModel();
        Agent::query()->create([
            'slug' => 'inactive-agent',
            'name' => 'Inactive Agent',
            'ai_provider_model_id' => $providerModel->id,
            'instructions' => ['background' => ['Inactive']],
            'is_active' => false,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Runtime agent [inactive-agent] is inactive.');

        app(RuntimeAgentDefinitionRepository::class)->require('inactive-agent');
    }

    public function test_can_delete_agent_via_api(): void
    {
        $agent = Agent::query()->create([
            'slug' => 'runtime-assistant',
            'name' => 'Runtime Assistant',
            'ai_provider_model_id' => $this->providerModel()->id,
            'instructions' => ['background' => ['Runtime']],
        ]);

        $response = $this->deleteJson("/api/agents/{$agent->id}");

        $response->assertNoContent();
        $this->assertDatabaseMissing('agents', [
            'id' => $agent->id,
        ]);
    }

    private function providerModel(bool $isActive = true): AiProviderModel
    {
        $provider = AiProvider::query()->create([
            'slug' => uniqid('work-gemini-'),
            'name' => 'Work Gemini',
            'type' => 'gemini',
            'credentials' => [
                'key' => 'gemini-secret-key',
            ],
        ]);

        return $provider->models()->create([
            'slug' => uniqid('gemini-flash-'),
            'name' => 'Gemini Flash',
            'model' => uniqid('gemini-3.1-flash-'),
            'is_active' => $isActive,
        ]);
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
