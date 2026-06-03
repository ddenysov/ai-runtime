<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_state_entries', function (Blueprint $table): void {
            $table->string('source_key')->nullable()->after('entity_type');
            $table->unique([
                'scope',
                'conversation_id',
                'agent_slug',
                'entity_type',
                'source_key',
            ], 'agent_state_entries_source_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('agent_state_entries', function (Blueprint $table): void {
            $table->dropUnique('agent_state_entries_source_key_unique');
            $table->dropColumn('source_key');
        });
    }
};
