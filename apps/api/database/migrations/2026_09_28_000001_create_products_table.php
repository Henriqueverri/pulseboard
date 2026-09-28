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
        Schema::create('products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('name');
            $table->string('sku', 64)->nullable();
            $table->decimal('price', 12, 2);
            $table->string('status', 16)->default('active');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'sku']);
            $table->index(['organization_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
