<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAiProviderRequest;
use App\Models\AiProvider;
use Illuminate\Http\JsonResponse;

class AiProviderController extends Controller
{
    public function store(StoreAiProviderRequest $request): JsonResponse
    {
        $provider = AiProvider::query()->create($request->validated());

        return response()->json($provider, 201);
    }
}
