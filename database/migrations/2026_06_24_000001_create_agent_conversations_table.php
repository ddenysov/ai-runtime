<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_conversations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('first_agent_id')->constrained('agents');
            $table->foreignId('second_agent_id')->constrained('agents');
            $table->string('first_agent_context_id');
            $table->string('second_agent_context_id');
            $table->text('starter_prompt');
            $table->foreignId('next_agent_id')->constrained('agents');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_conversations');
    }
};
