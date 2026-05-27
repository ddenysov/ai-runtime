<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('a2a_task_push_notifications', function (Blueprint $table): void {
            $table->id();
            $table->string('a2a_task_id')->index();
            $table->string('url');
            $table->json('authentication')->nullable();
            $table->string('last_status')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->foreign('a2a_task_id')->references('id')->on('a2a_tasks')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('a2a_task_push_notifications');
    }
};
