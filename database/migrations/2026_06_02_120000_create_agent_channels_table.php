<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_channels', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('agent_id')->constrained('agents')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('type', 64);
            $table->text('settings')->nullable();
            $table->json('metadata')->nullable();
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('aggregate_version')->default(0);
            $table->timestamps();

            $table->unique(['agent_id', 'name']);
            $table->index('type');
            $table->index('enabled');
        });

        Schema::create('agent_channel_threads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_channel_id')->constrained('agent_channels')->cascadeOnDelete();
            $table->string('external_chat_id');
            $table->string('context_id', 255);
            $table->timestamps();

            $table->unique(['agent_channel_id', 'external_chat_id']);
            $table->index('context_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_channel_threads');
        Schema::dropIfExists('agent_channels');
    }
};
