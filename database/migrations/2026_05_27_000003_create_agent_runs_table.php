<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('agent_slug')->index();
            $table->string('state')->index();
            $table->string('workflow_resume_token')->nullable()->index();
            $table->json('input')->nullable();
            $table->json('output')->nullable();
            $table->json('conversation_state')->nullable();
            $table->timestamp('resumable_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_runs');
    }
};
