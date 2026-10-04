<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Existing transactions all come from the demo seeder, so source is backfilled
     * with "seed"; the default is then dropped so every new row states its source.
     * unique (organization_id, external_id) is the idempotency guarantee of ingestion.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('source', 16)->default('seed');
            $table->string('external_id', 128)->nullable();
            $table->foreignUuid('api_key_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            $table->char('ingest_fingerprint', 64)->nullable();

            $table->unique(['organization_id', 'external_id']);
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->string('source', 16)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'external_id']);
            $table->dropConstrainedForeignId('api_key_id');
            $table->dropColumn(['source', 'external_id', 'ingest_fingerprint']);
        });
    }
};
