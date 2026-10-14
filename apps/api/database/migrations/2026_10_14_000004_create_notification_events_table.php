<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per push an organization was sent. The unique key is claimed before sending, so
     * the same alert (one product's low stock, one day's goal) goes out at most once.
     */
    public function up(): void
    {
        Schema::create('notification_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('kind', 32);
            $table->string('dedupe_key', 100);
            $table->unsignedInteger('recipients')->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['organization_id', 'kind', 'dedupe_key']);
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_events');
    }
};
