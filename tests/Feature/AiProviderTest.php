<?php

namespace Tests\Feature;

use App\Models\AiProvider;
use App\Models\AiProviderModel;
use App\Neuron\Providers\ConfiguredRuntimeAiProviderFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
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
            'is_active' => true,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('name', 'Work Gemini')
            ->assertJsonPath('slug', 'work-gemini')
            ->assertJsonPath('type', 'gemini')
            ->assertJsonPath('masked_credentials.key', 'gemi******-key')
            ->assertJsonMissing(['credentials']);

        $this->assertDatabaseHas('ai_providers', [
            'slug' => 'work-gemini',
            'name' => 'Work Gemini',
            'type' => 'gemini',
            'is_active' => true,
        ]);
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
            ->assertJsonValidationErrors(['slug', 'name', 'type', 'credentials.key']);
    }
}
