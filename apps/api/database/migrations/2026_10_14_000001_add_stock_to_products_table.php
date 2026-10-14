<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stock is owned by the integrating system (the POS) and only mirrored here.
     * stock_updated_at keeps microseconds: it orders updates that arrive out of order.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('stock_tracked')->default(false);
            $table->integer('stock_quantity')->default(0);
            $table->integer('stock_min')->default(0);
            $table->timestamp('stock_updated_at', 6)->nullable();

            $table->index(['organization_id', 'stock_tracked']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'stock_tracked']);
            $table->dropColumn(['stock_tracked', 'stock_quantity', 'stock_min', 'stock_updated_at']);
        });
    }
};
