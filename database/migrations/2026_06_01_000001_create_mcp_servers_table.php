<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_servers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name')->unique();
            $table->string('transport')->default('stdio')->index();
            $table->string('command', 1024);
            $table->json('args')->nullable();
            $table->string('cwd', 2048)->nullable();
            $table->text('env')->nullable();
            $table->json('metadata')->nullable();
            $table->boolean('enabled')->default(true)->index();
            $table->unsignedInteger('aggregate_version')->default(0);
            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_test_status')->nullable();
            $table->text('last_test_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_servers');
    }
};
