<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The id of the record in the integrating system. NULLs are distinct in the
     * unique index, so records without an external id never conflict.
     */
    public function up(): void
    {
        foreach (['customers', 'products'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->string('external_id', 128)->nullable();

                $table->unique(['organization_id', 'external_id']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['customers', 'products'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropUnique(['organization_id', 'external_id']);
                $table->dropColumn('external_id');
            });
        }
    }
};
