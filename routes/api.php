<?php

use App\A2A\Http\A2AJsonRpcController;
use App\A2A\Http\A2ANotificationController;
use App\A2A\Http\AgentCardController;
use App\Http\Controllers\AgentChatController;
use App\Http\Controllers\AgentController;
use App\Http\Controllers\AgentInstructionsGeneratorController;
use App\Http\Controllers\AiProviderController;
use App\Http\Controllers\SettingsController;
use App\Mcp\Http\Controllers\McpServerController;
use Illuminate\Support\Facades\Route;

Route::get('/settings', [SettingsController::class, 'show']);
Route::patch('/settings', [SettingsController::class, 'update']);

Route::get('/agents', [AgentController::class, 'index']);
Route::post('/agents', [AgentController::class, 'store']);
Route::get('/agents/{agent}/chats', [AgentChatController::class, 'index']);
Route::post('/agents/{agent}/chat', [AgentChatController::class, 'store']);
Route::get('/agents/{agent}/chat/{contextId}', [AgentChatController::class, 'show']);
Route::get('/agents/{agent}/chat/{run}/events', [AgentChatController::class, 'events'])->name('agents.chat.events');
Route::get('/agents/{agent}', [AgentController::class, 'show']);
Route::post('/agents/{agent}/generate-instructions', [AgentInstructionsGeneratorController::class, 'store']);
Route::put('/agents/{agent}', [AgentController::class, 'update']);
Route::delete('/agents/{agent}', [AgentController::class, 'destroy']);

Route::get('/ai-providers', [AiProviderController::class, 'index']);
Route::post('/ai-providers/test-connection', [AiProviderController::class, 'testConnection']);
Route::post('/ai-providers', [AiProviderController::class, 'store']);
Route::get('/ai-providers/{aiProvider}', [AiProviderController::class, 'show']);
Route::put('/ai-providers/{aiProvider}', [AiProviderController::class, 'update']);
Route::delete('/ai-providers/{aiProvider}', [AiProviderController::class, 'destroy']);

Route::get('/mcp-servers', [McpServerController::class, 'index']);
Route::post('/mcp-servers', [McpServerController::class, 'store']);
Route::get('/mcp-servers/{mcpServer}/tools', [McpServerController::class, 'tools']);
Route::post('/mcp-servers/{mcpServer}/test', [McpServerController::class, 'test']);
Route::get('/mcp-servers/{mcpServer}', [McpServerController::class, 'show']);
Route::put('/mcp-servers/{mcpServer}', [McpServerController::class, 'update']);
Route::delete('/mcp-servers/{mcpServer}', [McpServerController::class, 'destroy']);

Route::get('/a2a/{agent}/.well-known/agent-card.json', AgentCardController::class);

Route::middleware('auth.a2a')->group(function (): void {
    Route::post('/a2a/notifications', A2ANotificationController::class);
    Route::post('/a2a/{agent}', A2AJsonRpcController::class);
});
