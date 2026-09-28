<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Timestamps stay in UTC; this is the IANA timezone that defines the
     * organization's business calendar (reporting days, weeks and months).
     */
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('timezone', 64)->default('America/Sao_Paulo');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('timezone');
        });
    }
};
