<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('a2a_notification_events', function (Blueprint $table): void {
            $table->id();
            $table->string('kind')->index();
            $table->string('task_id')->nullable()->index();
            $table->string('context_id')->nullable()->index();
            $table->json('payload');
            $table->json('headers')->nullable();
            $table->string('source_ip')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->text('processing_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('a2a_notification_events');
    }
};
