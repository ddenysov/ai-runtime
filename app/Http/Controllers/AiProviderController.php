<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAiProviderRequest;
use App\Http\Requests\TestAiProviderConnectionRequest;
use App\Http\Requests\UpdateAiProviderRequest;
use App\Models\Agent;
use App\Models\AiProvider;
use App\Models\AiProviderModel;
use App\Neuron\Providers\AiProviderConnectionTester;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedInclude;
use Spatie\QueryBuilder\QueryBuilder;
use Throwable;

class AiProviderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max($request->integer('per_page', 10), 1), 50);

        $providers = QueryBuilder::for(AiProvider::class)
            ->allowedFilters(
                AllowedFilter::callback('search', function (Builder $query, mixed $value): void {
                    $query->where(function (Builder $query) use ($value): void {
                        $query
                            ->where('name', 'like', "%{$value}%")
                            ->orWhere('slug', 'like', "%{$value}%");
                    });
                }),
                AllowedFilter::exact('type'),
                AllowedFilter::exact('is_active'),
            )
            ->allowedSorts(
                'name',
                'type',
                'is_active',
                'created_at',
                'updated_at',
            )
            ->allowedIncludes(
                'models',
                AllowedInclude::count('modelsCount'),
            )
            ->defaultSort('-updated_at')
            ->paginate($perPage)
            ->appends($request->query());

        return response()->json($providers);
    }

    public function store(StoreAiProviderRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $providerAttributes = Arr::except($validated, 'models');
        $models = $validated['models'];

        $provider = DB::transaction(function () use ($providerAttributes, $models): AiProvider {
            $provider = AiProvider::query()->create($providerAttributes);

            foreach ($models as $model) {
                $provider->models()->create([
                    'slug' => $this->makeModelSlug($provider->slug, $model['model']),
                    'name' => ($model['name'] ?? null) ?: $model['model'],
                    'model' => $model['model'],
                    'description' => $model['description'] ?? null,
                    'is_active' => $model['is_active'] ?? true,
                ]);
            }

            return $provider;
        });

        return response()->json($provider->load('models'), 201);
    }

    public function show(AiProvider $aiProvider): JsonResponse
    {
        return response()->json($aiProvider->load('models'));
    }

    public function update(UpdateAiProviderRequest $request, AiProvider $aiProvider): JsonResponse
    {
        $validated = $request->validated();
        $providerAttributes = Arr::except($validated, 'models');
        $models = $validated['models'];

        if (isset($providerAttributes['credentials'])) {
            $providerAttributes['credentials'] = $this->mergeCredentials(
                $aiProvider->credentials ?? [],
                $providerAttributes['credentials'],
            );

            if ($providerAttributes['credentials'] === $aiProvider->credentials) {
                unset($providerAttributes['credentials']);
            }
        }

        $provider = DB::transaction(function () use ($aiProvider, $providerAttributes, $models): AiProvider {
            $aiProvider->fill($providerAttributes);
            $aiProvider->save();

            $this->syncProviderModels($aiProvider, $models);

            return $aiProvider;
        });

        return response()->json($provider->load('models'));
    }

    public function destroy(AiProvider $aiProvider)
    {
        $aiProvider->delete();

        return response()->noContent();
    }

    public function testConnection(
        TestAiProviderConnectionRequest $request,
        AiProviderConnectionTester $connectionTester,
    ): JsonResponse {
        $validated = $request->validated();
        $credentials = $validated['credentials'] ?? [];

        if (isset($validated['ai_provider_id'])) {
            $storedProvider = AiProvider::query()->findOrFail($validated['ai_provider_id']);
            $credentials = $this->mergeCredentials($storedProvider->credentials ?? [], $credentials);
        }

        try {
            $connectionTester->assertProviderValid(
                new AiProvider([
                    'type' => $validated['type'],
                    'credentials' => $credentials,
                ]),
                $validated['model'],
            );
        } catch (Throwable $exception) {
            throw ValidationException::withMessages([
                'model' => "Could not validate provider credentials with this model: {$exception->getMessage()}",
            ]);
        }

        return response()->json([
            'message' => 'Provider connection is valid.',
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $models
     */
    private function syncProviderModels(AiProvider $provider, array $models): void
    {
        $submittedIds = collect($models)
            ->pluck('id')
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->values();

        $modelsToDelete = $provider->models()
            ->whereNotIn('id', $submittedIds)
            ->get();

        foreach ($modelsToDelete as $model) {
            if (Agent::query()->where('ai_provider_model_id', $model->id)->exists()) {
                throw ValidationException::withMessages([
                    'models' => "Model [{$model->name}] cannot be removed because agents still use it.",
                ]);
            }

            $model->delete();
        }

        foreach ($models as $modelData) {
            $attributes = [
                'name' => ($modelData['name'] ?? null) ?: $modelData['model'],
                'model' => $modelData['model'],
                'description' => $modelData['description'] ?? null,
                'is_active' => $modelData['is_active'] ?? true,
            ];

            if (isset($modelData['id'])) {
                /** @var AiProviderModel $existingModel */
                $existingModel = $provider->models()->findOrFail($modelData['id']);

                if ($existingModel->model !== $modelData['model']) {
                    $attributes['model'] = $modelData['model'];
                    $attributes['slug'] = $this->makeModelSlug($provider->slug, $modelData['model']);
                }

                $existingModel->update($attributes);

                continue;
            }

            $provider->models()->create([
                ...$attributes,
                'slug' => $this->makeModelSlug($provider->slug, $modelData['model']),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    private function mergeCredentials(array $existing, array $incoming): array
    {
        foreach ($incoming as $key => $value) {
            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            $existing[$key] = $value;
        }

        return $existing;
    }

    private function makeModelSlug(string $providerSlug, string $model): string
    {
        return Str::slug("{$providerSlug}-".str_replace('.', '-', $model));
    }
}
