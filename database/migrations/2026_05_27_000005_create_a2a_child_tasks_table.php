<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('a2a_child_tasks', function (Blueprint $table): void {
            $table->id();
            $table->uuid('agent_run_id')->index();
            $table->uuid('tool_call_id')->unique();
            $table->string('remote_agent_slug')->index();
            $table->string('remote_task_id')->unique();
            $table->string('remote_context_id')->nullable()->index();
            $table->string('state')->index();
            $table->json('request_payload')->nullable();
            $table->json('last_notification')->nullable();
            $table->timestamps();

            $table->foreign('agent_run_id')->references('id')->on('agent_runs')->cascadeOnDelete();
            $table->foreign('tool_call_id')->references('id')->on('agent_tool_calls')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('a2a_child_tasks');
    }
};
