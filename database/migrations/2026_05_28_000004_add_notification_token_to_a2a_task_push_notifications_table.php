<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('a2a_task_push_notifications', function (Blueprint $table): void {
            $table->string('notification_token')->nullable()->after('authentication');
        });
    }

    public function down(): void
    {
        Schema::table('a2a_task_push_notifications', function (Blueprint $table): void {
            $table->dropColumn('notification_token');
        });
    }
};
