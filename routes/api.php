<?php

use App\A2A\Http\A2AJsonRpcController;
use App\A2A\Http\A2ANotificationController;
use App\A2A\Http\AgentCardController;
use App\Channels\Http\Controllers\AgentChannelController;
use App\Channels\Http\Controllers\TelegramAgentWebhookController;
use App\Http\Controllers\AgentChatController;
use App\Http\Controllers\AgentController;
use App\Http\Controllers\AgentInstructionsGeneratorController;
use App\Http\Controllers\AgentScheduleController;
use App\Http\Controllers\AgentStateProcessorController;
use App\Http\Controllers\AiProviderController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\Gatekeeper\GatekeeperTelegramWebhookController;
use App\Http\Controllers\SettingsController;
use App\Mcp\Http\Controllers\McpServerController;
use Illuminate\Support\Facades\Route;

Route::post('/integrations/gatekeeper/telegram/webhook', GatekeeperTelegramWebhookController::class);
Route::post('/integrations/telegram/webhooks/{agentChannel}', TelegramAgentWebhookController::class);

Route::middleware('web')->group(function (): void {
    Route::get('/auth/user', [SessionController::class, 'show']);
    Route::post('/auth/login', [SessionController::class, 'store'])->middleware('guest');
    Route::post('/auth/logout', [SessionController::class, 'destroy'])->middleware('auth');

    Route::middleware('auth')->group(function (): void {
        Route::get('/settings', [SettingsController::class, 'show']);
        Route::patch('/settings', [SettingsController::class, 'update']);

        Route::get('/agent-channels', [AgentChannelController::class, 'index']);
        Route::post('/agent-channels', [AgentChannelController::class, 'store']);
        Route::get('/agent-channels/{agentChannel}', [AgentChannelController::class, 'show']);
        Route::patch('/agent-channels/{agentChannel}', [AgentChannelController::class, 'update']);
        Route::delete('/agent-channels/{agentChannel}', [AgentChannelController::class, 'destroy']);
        Route::post('/agent-channels/{agentChannel}/telegram/webhook', [AgentChannelController::class, 'setTelegramWebhook']);
        Route::delete('/agent-channels/{agentChannel}/telegram/webhook', [AgentChannelController::class, 'deleteTelegramWebhook']);

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

        Route::get('/agents/{agent}/schedules', [AgentScheduleController::class, 'index']);
        Route::post('/agents/{agent}/schedules', [AgentScheduleController::class, 'store']);
        Route::get('/agent-schedules/{agentSchedule}', [AgentScheduleController::class, 'show']);
        Route::patch('/agent-schedules/{agentSchedule}', [AgentScheduleController::class, 'update']);
        Route::delete('/agent-schedules/{agentSchedule}', [AgentScheduleController::class, 'destroy']);
        Route::post('/agent-schedules/{agentSchedule}/run-now', [AgentScheduleController::class, 'runNow']);

        Route::get('/agent-state-processors', [AgentStateProcessorController::class, 'index']);
        Route::post('/agent-state-processors', [AgentStateProcessorController::class, 'store']);
        Route::get('/agent-state-processors/{agentStateProcessor}', [AgentStateProcessorController::class, 'show']);
        Route::put('/agent-state-processors/{agentStateProcessor}', [AgentStateProcessorController::class, 'update']);
        Route::delete('/agent-state-processors/{agentStateProcessor}', [AgentStateProcessorController::class, 'destroy']);

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
    });
});

Route::get('/a2a/{agent}/.well-known/agent-card.json', AgentCardController::class);

Route::middleware('auth.a2a')->group(function (): void {
    Route::post('/a2a/notifications', A2ANotificationController::class);
    Route::post('/a2a/{agent}', A2AJsonRpcController::class);
});
