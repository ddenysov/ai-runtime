<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateSettingsRequest;
use App\Support\AppSettings;
use Illuminate\Http\JsonResponse;

class SettingsController extends Controller
{
    public function show(AppSettings $settings): JsonResponse
    {
        return response()->json([
            'data' => $settings->all(),
        ]);
    }

    public function update(UpdateSettingsRequest $request, AppSettings $settings): JsonResponse
    {
        return response()->json([
            'data' => $settings->update($request->validated()),
        ]);
    }
}
