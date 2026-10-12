<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Managed keys are provisioned by `pulseboard:demo` from the environment and
     * cannot be revoked through the API.
     */
    public function up(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->boolean('managed')->default(false)->after('secret_hash');
        });
    }

    public function down(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->dropColumn('managed');
        });
    }
};
