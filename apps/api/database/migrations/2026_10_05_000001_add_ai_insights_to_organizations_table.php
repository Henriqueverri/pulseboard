<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-organization opt-in for PulseBoard Insights: nothing is sent to an AI
     * provider until an owner enables it. Null means disabled.
     */
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->timestamp('ai_insights_enabled_at')->nullable();
            $table->foreignUuid('ai_insights_enabled_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ai_insights_enabled_by');
            $table->dropColumn('ai_insights_enabled_at');
        });
    }
};
