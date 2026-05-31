<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAiProviderRequest;
use App\Http\Requests\TestAiProviderConnectionRequest;
use App\Models\AiProvider;
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

        try {
            $connectionTester->assertProviderValid(
                new AiProvider(Arr::only($validated, ['type', 'credentials'])),
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

    private function makeModelSlug(string $providerSlug, string $model): string
    {
        return Str::slug("{$providerSlug}-".str_replace('.', '-', $model));
    }
}
