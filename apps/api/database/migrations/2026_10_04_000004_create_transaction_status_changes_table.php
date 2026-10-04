<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Status history of each transaction; transactions.status stays the denormalized
     * current status read by analytics. The lifecycle never revisits a status, so
     * unique (transaction_id, to_status) rejects duplicated transitions in the database.
     */
    public function up(): void
    {
        Schema::create('transaction_status_changes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignUuid('transaction_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('from_status', 16)->nullable();
            $table->string('to_status', 16);
            $table->timestamp('occurred_at');
            $table->string('source', 16);
            $table->foreignUuid('api_key_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['transaction_id', 'to_status']);
            $table->index(['transaction_id', 'occurred_at']);
        });

        $this->backfill();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transaction_status_changes');
    }

    /**
     * Every existing transaction gets the path that leads to its current status:
     * null -> paid | pending; refunded = null -> paid -> refunded; canceled = null -> pending -> canceled.
     * The real date of a refund or cancellation is unknown, so every change uses occurred_at.
     */
    private function backfill(): void
    {
        $uuid = $this->uuidExpression();

        DB::statement(<<<SQL
            INSERT INTO transaction_status_changes
                (id, organization_id, transaction_id, from_status, to_status, occurred_at, source, created_at)
            SELECT {$uuid}, organization_id, id, NULL,
                CASE status WHEN 'refunded' THEN 'paid' WHEN 'canceled' THEN 'pending' ELSE status END,
                occurred_at, source, COALESCE(created_at, CURRENT_TIMESTAMP)
            FROM transactions
            SQL);

        DB::statement(<<<SQL
            INSERT INTO transaction_status_changes
                (id, organization_id, transaction_id, from_status, to_status, occurred_at, source, created_at)
            SELECT {$uuid}, organization_id, id,
                CASE status WHEN 'refunded' THEN 'paid' ELSE 'pending' END,
                status, occurred_at, source, COALESCE(created_at, CURRENT_TIMESTAMP)
            FROM transactions
            WHERE status IN ('refunded', 'canceled')
            SQL);
    }

    /**
     * A random (v4) UUID per row: PostgreSQL (production) has gen_random_uuid(); SQLite (tests) builds one.
     */
    private function uuidExpression(): string
    {
        if (DB::getDriverName() === 'pgsql') {
            return 'gen_random_uuid()';
        }

        return "lower(hex(randomblob(4)) || '-' || hex(randomblob(2)) || '-4' || substr(hex(randomblob(2)), 2) || '-'"
            ." || substr('89ab', 1 + (abs(random()) % 4), 1) || substr(hex(randomblob(2)), 2) || '-' || hex(randomblob(6)))";
    }
};
