<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAiProviderRequest;
use App\Models\AiProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedInclude;
use Spatie\QueryBuilder\QueryBuilder;

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
                AllowedInclude::count('modelsCount'),
            )
            ->defaultSort('-updated_at')
            ->paginate($perPage)
            ->appends($request->query());

        return response()->json($providers);
    }

    public function store(StoreAiProviderRequest $request): JsonResponse
    {
        $provider = AiProvider::query()->create($request->validated());

        return response()->json($provider, 201);
    }
}
