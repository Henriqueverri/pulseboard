<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Expo push tokens of the mobile app. Each device belongs to the personal access token it
     * registered with, so revoking that token (logout, expiry, a new login on the same device)
     * also stops the pushes. A token is unique: the last user to register it owns it.
     */
    public function up(): void
    {
        Schema::create('push_devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('personal_access_token_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('token')->unique();
            $table->string('platform', 16);
            $table->timestamp('last_seen_at');
            $table->timestamps();

            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('push_devices');
    }
};
