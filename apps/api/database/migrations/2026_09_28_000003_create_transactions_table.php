<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignUuid('customer_id')
                ->constrained()
                ->restrictOnDelete();
            $table->string('status', 16);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index('customer_id');
            $table->index(['organization_id', 'occurred_at']);
            $table->index(['organization_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
