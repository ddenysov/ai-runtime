<?php

use App\A2A\Http\A2AJsonRpcController;
use App\A2A\Http\A2ANotificationController;
use App\A2A\Http\AgentCardController;
use App\Http\Controllers\AgentChatController;
use App\Http\Controllers\AgentController;
use App\Http\Controllers\AiProviderController;
use Illuminate\Support\Facades\Route;

Route::get('/agents', [AgentController::class, 'index']);
Route::post('/agents', [AgentController::class, 'store']);
Route::post('/agents/{agent}/chat', [AgentChatController::class, 'store']);
Route::get('/agents/{agent}/chat/{run}/events', [AgentChatController::class, 'events'])->name('agents.chat.events');
Route::get('/agents/{agent}', [AgentController::class, 'show']);
Route::delete('/agents/{agent}', [AgentController::class, 'destroy']);

Route::get('/ai-providers', [AiProviderController::class, 'index']);
Route::post('/ai-providers/test-connection', [AiProviderController::class, 'testConnection']);
Route::post('/ai-providers', [AiProviderController::class, 'store']);
Route::delete('/ai-providers/{aiProvider}', [AiProviderController::class, 'destroy']);

Route::get('/a2a/{agent}/.well-known/agent-card.json', AgentCardController::class);

Route::middleware('auth.a2a')->group(function (): void {
    Route::post('/a2a/notifications', A2ANotificationController::class);
    Route::post('/a2a/{agent}', A2AJsonRpcController::class);
});
