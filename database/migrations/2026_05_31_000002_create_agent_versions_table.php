<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('configuration');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['agent_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_versions');
    }
};
