<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_state_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('agent_state_groups')->cascadeOnDelete();
            $table->string('scope')->index();
            $table->string('conversation_id')->nullable()->index();
            $table->string('name');
            $table->string('slug');
            $table->timestamps();

            $table->unique(['scope', 'conversation_id', 'parent_id', 'slug']);
        });

        Schema::create('agent_state_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('group_id')->nullable()->constrained('agent_state_groups')->nullOnDelete();
            $table->string('scope')->index();
            $table->string('conversation_id')->nullable()->index();
            $table->string('agent_slug')->index();
            $table->string('entity_type')->nullable()->index();
            $table->string('title');
            $table->text('summary')->nullable();
            $table->json('content');
            $table->timestamps();

            $table->index(['scope', 'conversation_id', 'entity_type']);
            $table->index(['scope', 'conversation_id', 'group_id']);
        });

        Schema::create('agent_state_tags', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::create('agent_state_entry_tag', function (Blueprint $table): void {
            $table->foreignUuid('agent_state_entry_id')->constrained('agent_state_entries')->cascadeOnDelete();
            $table->foreignId('agent_state_tag_id')->constrained('agent_state_tags')->cascadeOnDelete();

            $table->primary(['agent_state_entry_id', 'agent_state_tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_state_entry_tag');
        Schema::dropIfExists('agent_state_tags');
        Schema::dropIfExists('agent_state_entries');
        Schema::dropIfExists('agent_state_groups');
    }
};
