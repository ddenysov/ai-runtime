<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\AiProvider;
use App\Models\AiProviderModel;
use App\Neuron\RuntimeAgentDefinitionRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
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
}
