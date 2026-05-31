<?php

use App\A2A\Http\A2AJsonRpcController;
use App\A2A\Http\A2ANotificationController;
use App\A2A\Http\AgentCardController;
use App\Http\Controllers\AiProviderController;
use Illuminate\Support\Facades\Route;

Route::get('/ai-providers', [AiProviderController::class, 'index']);
Route::post('/ai-providers/test-connection', [AiProviderController::class, 'testConnection']);
Route::post('/ai-providers', [AiProviderController::class, 'store']);
Route::delete('/ai-providers/{aiProvider}', [AiProviderController::class, 'destroy']);

Route::get('/a2a/{agent}/.well-known/agent-card.json', AgentCardController::class);

Route::middleware('auth.a2a')->group(function (): void {
    Route::post('/a2a/notifications', A2ANotificationController::class);
    Route::post('/a2a/{agent}', A2AJsonRpcController::class);
});
