<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('a2a_tasks', function (Blueprint $table): void {
            $table->unsignedInteger('attempts')->default(0)->after('payload');
            $table->string('last_error_kind')->nullable()->index()->after('attempts');
            $table->text('last_error_message')->nullable()->after('last_error_kind');
            $table->timestamp('next_attempt_at')->nullable()->index()->after('last_error_message');
            $table->timestamp('failed_at')->nullable()->after('next_attempt_at');
        });

        Schema::table('a2a_child_tasks', function (Blueprint $table): void {
            $table->unsignedInteger('attempts')->default(0)->after('last_notification');
            $table->string('last_error_kind')->nullable()->index()->after('attempts');
            $table->text('last_error_message')->nullable()->after('last_error_kind');
            $table->timestamp('next_attempt_at')->nullable()->index()->after('last_error_message');
            $table->timestamp('failed_at')->nullable()->after('next_attempt_at');
        });

        Schema::table('agent_runs', function (Blueprint $table): void {
            $table->unsignedInteger('attempts')->default(0)->after('resumable_at');
            $table->string('last_error_kind')->nullable()->index()->after('attempts');
            $table->text('last_error_message')->nullable()->after('last_error_kind');
            $table->timestamp('next_attempt_at')->nullable()->index()->after('last_error_message');
            $table->timestamp('failed_at')->nullable()->after('next_attempt_at');
        });

        Schema::table('agent_tool_calls', function (Blueprint $table): void {
            $table->string('error_kind')->nullable()->index()->after('error');
        });
    }

    public function down(): void
    {
        Schema::table('agent_tool_calls', function (Blueprint $table): void {
            $table->dropColumn('error_kind');
        });

        Schema::table('agent_runs', function (Blueprint $table): void {
            $table->dropColumn([
                'attempts',
                'last_error_kind',
                'last_error_message',
                'next_attempt_at',
                'failed_at',
            ]);
        });

        Schema::table('a2a_child_tasks', function (Blueprint $table): void {
            $table->dropColumn([
                'attempts',
                'last_error_kind',
                'last_error_message',
                'next_attempt_at',
                'failed_at',
            ]);
        });

        Schema::table('a2a_tasks', function (Blueprint $table): void {
            $table->dropColumn([
                'attempts',
                'last_error_kind',
                'last_error_message',
                'next_attempt_at',
                'failed_at',
            ]);
        });
    }
};
