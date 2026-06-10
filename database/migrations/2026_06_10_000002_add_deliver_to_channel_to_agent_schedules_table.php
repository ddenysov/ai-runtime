<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_schedules', function (Blueprint $table): void {
            $table->boolean('deliver_to_channel')->default(false)->after('enabled');
        });
    }

    public function down(): void
    {
        Schema::table('agent_schedules', function (Blueprint $table): void {
            $table->dropColumn('deliver_to_channel');
        });
    }
};
