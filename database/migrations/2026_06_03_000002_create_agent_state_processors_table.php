<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_state_processors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('extractor_agent_id')->constrained('agents')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('instructions');
            $table->json('response_schema')->nullable();
            $table->json('entity_types')->nullable();
            $table->string('default_scope')->default('conversation');
            $table->decimal('min_confidence', 3, 2)->default(0.70);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('agent_state_processor_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agent_id')->constrained('agents')->cascadeOnDelete();
            $table->foreignId('agent_state_processor_id')->constrained('agent_state_processors')->cascadeOnDelete();
            $table->boolean('is_enabled')->default(true);
            $table->string('trigger')->default('after_response');
            $table->string('scope')->default('conversation');
            $table->string('injection_title')->default('Runtime State');
            $table->text('injection_instructions')->nullable();
            $table->json('state_filters')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['agent_id', 'agent_state_processor_id']);
            $table->index(['agent_id', 'is_enabled', 'trigger']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_state_processor_assignments');
        Schema::dropIfExists('agent_state_processors');
    }
};
