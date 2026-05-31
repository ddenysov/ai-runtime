<?php

namespace Tests\Feature;

use App\Models\AiProvider;
use App\Models\AiProviderModel;
use App\Neuron\Providers\AiProviderConnectionTester;
use App\Neuron\Providers\ConfiguredRuntimeAiProviderFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Mockery;
use NeuronAI\Providers\Gemini\Gemini;
use Tests\TestCase;

class AiProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_ai_provider_credentials_are_encrypted_and_masked(): void
    {
        $provider = AiProvider::query()->create([
            'slug' => 'work-gemini',
            'name' => 'Work Gemini',
            'type' => 'gemini',
            'credentials' => [
                'key' => 'gemini-secret-key',
            ],
        ]);

        $storedCredentials = DB::table('ai_providers')->where('id', $provider->id)->value('credentials');

        $this->assertIsString($storedCredentials);
        $this->assertStringNotContainsString('gemini-secret-key', $storedCredentials);
        $this->assertSame('gemini-secret-key', $provider->fresh()->credential('key'));
        $this->assertSame(['key' => 'gemi******-key'], $provider->fresh()->masked_credentials);
        $this->assertArrayNotHasKey('credentials', $provider->fresh()->toArray());
    }

    public function test_gemini_provider_requires_key_credential(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('AI provider credential [key] is required.');

        AiProvider::query()->create([
            'slug' => 'broken-gemini',
            'name' => 'Broken Gemini',
            'type' => 'gemini',
            'credentials' => [],
        ]);
    }

    public function test_provider_models_store_display_name_and_open_model_string(): void
    {
        $provider = AiProvider::query()->create([
            'slug' => 'work-gemini',
            'name' => 'Work Gemini',
            'type' => 'gemini',
            'credentials' => [
                'key' => 'gemini-secret-key',
            ],
        ]);

        $model = $provider->models()->create([
            'slug' => 'gemini-flash-31',
            'name' => 'Gemini Flash 3.1',
            'model' => 'gemini-3.1-flash',
        ]);

        $this->assertSame('Gemini Flash 3.1', $model->name);
        $this->assertSame('gemini-3.1-flash', $model->model);
        $this->assertTrue($provider->models()->where('slug', 'gemini-flash-31')->exists());
    }

    public function test_runtime_factory_resolves_active_database_provider_model_by_slug(): void
    {
        $provider = AiProvider::query()->create([
            'slug' => 'work-gemini',
            'name' => 'Work Gemini',
            'type' => 'gemini',
            'credentials' => [
                'key' => 'gemini-secret-key',
            ],
        ]);
        AiProviderModel::query()->create([
            'ai_provider_id' => $provider->id,
            'slug' => 'gemini-flash-31',
            'name' => 'Gemini Flash 3.1',
            'model' => 'gemini-3.1-flash',
        ]);

        $provider = (new ConfiguredRuntimeAiProviderFactory)->make([
            'ai_provider_model_slug' => 'gemini-flash-31',
        ]);

        $this->assertInstanceOf(Gemini::class, $provider);
    }

    public function test_runtime_factory_requires_explicit_provider_reference(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Runtime agent must define ai_provider_model_id or ai_provider_model_slug.');

        (new ConfiguredRuntimeAiProviderFactory)->make([]);
    }

    public function test_can_create_ai_provider_via_api(): void
    {
        $response = $this->postJson('/api/ai-providers', [
            'slug' => 'work-gemini',
            'name' => 'Work Gemini',
            'description' => 'Primary Gemini account',
            'type' => 'gemini',
            'credentials' => [
                'key' => 'gemini-secret-key',
            ],
            'models' => [
                [
                    'model' => 'gemini-3.1-flash',
                    'name' => 'Gemini Flash 3.1',
                ],
            ],
            'is_active' => true,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('name', 'Work Gemini')
            ->assertJsonPath('slug', 'work-gemini')
            ->assertJsonPath('type', 'gemini')
            ->assertJsonPath('masked_credentials.key', 'gemi******-key')
            ->assertJsonPath('models.0.model', 'gemini-3.1-flash')
            ->assertJsonPath('models.0.name', 'Gemini Flash 3.1')
            ->assertJsonMissing(['credentials']);

        $this->assertDatabaseHas('ai_providers', [
            'slug' => 'work-gemini',
            'name' => 'Work Gemini',
            'type' => 'gemini',
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('ai_provider_models', [
            'slug' => 'work-gemini-gemini-3-1-flash',
            'name' => 'Gemini Flash 3.1',
            'model' => 'gemini-3.1-flash',
        ]);
    }

    public function test_can_test_ai_provider_connection_via_api(): void
    {
        $this->mock(AiProviderConnectionTester::class, function ($mock): void {
            $mock
                ->shouldReceive('assertProviderValid')
                ->once()
                ->with(Mockery::type(AiProvider::class), 'gemini-3.1-flash');
        });

        $response = $this->postJson('/api/ai-providers/test-connection', [
            'type' => 'gemini',
            'credentials' => [
                'key' => 'gemini-secret-key',
            ],
            'model' => 'gemini-3.1-flash',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('message', 'Provider connection is valid.');
    }

    public function test_test_ai_provider_connection_api_returns_validation_error_when_connection_fails(): void
    {
        $this->mock(AiProviderConnectionTester::class, function ($mock): void {
            $mock
                ->shouldReceive('assertProviderValid')
                ->once()
                ->andThrow(new InvalidArgumentException('Invalid API key.'));
        });

        $response = $this->postJson('/api/ai-providers/test-connection', [
            'type' => 'gemini',
            'credentials' => [
                'key' => 'bad-key',
            ],
            'model' => 'gemini-3.1-flash',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['model']);
    }

    public function test_create_ai_provider_api_validates_payload(): void
    {
        $response = $this->postJson('/api/ai-providers', [
            'slug' => 'Invalid Slug',
            'name' => '',
            'type' => 'unknown',
            'credentials' => [],
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['slug', 'name', 'type', 'credentials.key', 'models']);
    }

    public function test_can_list_ai_providers_with_search_filters_sorting_and_pagination(): void
    {
        $sandbox = AiProvider::query()->create([
            'slug' => 'sandbox-gemini',
            'name' => 'Sandbox Gemini',
            'type' => 'gemini',
            'credentials' => [
                'key' => 'sandbox-secret-key',
            ],
            'is_active' => false,
        ]);

        $production = AiProvider::query()->create([
            'slug' => 'production-gemini',
            'name' => 'Production Gemini',
            'type' => 'gemini',
            'credentials' => [
                'key' => 'production-secret-key',
            ],
            'is_active' => true,
        ]);

        $production->models()->create([
            'slug' => 'production-flash',
            'name' => 'Production Flash',
            'model' => 'gemini-3.1-flash',
        ]);
        $production->models()->create([
            'slug' => 'production-pro',
            'name' => 'Production Pro',
            'model' => 'gemini-3.1-pro',
        ]);

        $response = $this->getJson('/api/ai-providers?'.http_build_query([
            'filter' => [
                'search' => 'gemini',
                'type' => 'gemini',
                'is_active' => true,
            ],
            'include' => 'modelsCount',
            'sort' => 'name',
            'page' => 1,
            'per_page' => 1,
        ]));

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'production-gemini')
            ->assertJsonPath('data.0.models_count', 2)
            ->assertJsonPath('current_page', 1)
            ->assertJsonPath('per_page', 1)
            ->assertJsonPath('total', 1);

        $this->assertArrayNotHasKey('credentials', $response->json('data.0'));
        $this->assertTrue($sandbox->is(AiProvider::query()->where('slug', 'sandbox-gemini')->first()));
    }

    public function test_can_delete_ai_provider_via_api(): void
    {
        $provider = AiProvider::query()->create([
            'slug' => 'work-gemini',
            'name' => 'Work Gemini',
            'type' => 'gemini',
            'credentials' => [
                'key' => 'gemini-secret-key',
            ],
        ]);

        $provider->models()->create([
            'slug' => 'work-gemini-gemini-3-1-flash',
            'name' => 'Gemini Flash 3.1',
            'model' => 'gemini-3.1-flash',
        ]);

        $response = $this->deleteJson("/api/ai-providers/{$provider->id}");

        $response->assertNoContent();

        $this->assertDatabaseMissing('ai_providers', [
            'id' => $provider->id,
        ]);
        $this->assertDatabaseMissing('ai_provider_models', [
            'ai_provider_id' => $provider->id,
        ]);
    }

    public function test_delete_ai_provider_api_returns_not_found_for_missing_provider(): void
    {
        $response = $this->deleteJson('/api/ai-providers/999999');

        $response->assertNotFound();
    }

    public function test_can_sort_ai_providers_descending(): void
    {
        AiProvider::query()->create([
            'slug' => 'alpha-gemini',
            'name' => 'Alpha Gemini',
            'type' => 'gemini',
            'credentials' => [
                'key' => 'alpha-secret-key',
            ],
        ]);

        AiProvider::query()->create([
            'slug' => 'zeta-gemini',
            'name' => 'Zeta Gemini',
            'type' => 'gemini',
            'credentials' => [
                'key' => 'zeta-secret-key',
            ],
        ]);

        $response = $this->getJson('/api/ai-providers?sort=-name');

        $response
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'zeta-gemini')
            ->assertJsonPath('data.1.slug', 'alpha-gemini');
    }
}
