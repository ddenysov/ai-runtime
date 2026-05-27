<?php

use App\A2A\Http\A2AJsonRpcController;
use App\A2A\Http\AgentCardController;
use Illuminate\Support\Facades\Route;

Route::get('/a2a/{agent}/.well-known/agent-card.json', AgentCardController::class);

Route::middleware('auth.a2a')->group(function (): void {
    Route::post('/a2a/{agent}', A2AJsonRpcController::class);
});
