<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_tools', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->string('slug');
            $table->boolean('is_enabled')->default(true)->index();
            $table->json('config')->nullable();
            $table->timestamps();

            $table->unique(['agent_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_tools');
    }
};
