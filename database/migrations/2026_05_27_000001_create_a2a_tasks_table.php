<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('a2a_tasks', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('context_id')->index();
            $table->string('agent_slug')->index();
            $table->string('state')->index();
            $table->json('payload');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('a2a_tasks');
    }
};
