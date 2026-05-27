<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_tool_calls', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('agent_run_id')->index();
            $table->string('tool_name');
            $table->string('state')->index();
            $table->json('arguments')->nullable();
            $table->json('result')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->foreign('agent_run_id')->references('id')->on('agent_runs')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_tool_calls');
    }
};
