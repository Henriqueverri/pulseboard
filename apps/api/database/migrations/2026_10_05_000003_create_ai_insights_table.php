<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Validated AI answers, keyed by the fingerprint of the data, prompt and
     * model they were generated from: unchanged data never reaches the provider
     * twice. Invalid answers are never stored. Deleted when the organization
     * opts out.
     */
    public function up(): void
    {
        Schema::create('ai_insights', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('kind', 32);
            $table->char('fingerprint', 64);
            $table->string('model', 64);
            $table->string('prompt_version', 32);
            $table->json('content');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['organization_id', 'kind', 'fingerprint']);
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_insights');
    }
};
