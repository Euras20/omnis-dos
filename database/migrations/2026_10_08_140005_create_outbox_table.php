<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Foundation group 1 — transactional outbox for side-effects.
 *
 * Written inside the same command transaction as business state.
 * OutboxDispatcher claims with SKIP LOCKED.
 *
 * Source: 02-database.md §5.7, 09-events-jobs-queues.md §9.3, OutboxWriter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('aggregate_type', 64);
            $table->uuid('aggregate_id');
            $table->string('event_type', 128);
            $table->jsonb('payload')->default('{}');
            $table->string('status', 32)->default('pending'); // pending|processing|completed|failed|dead
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('available_at')->useCurrent();
            $table->timestampTz('locked_at')->nullable();
            $table->string('locked_by', 128)->nullable();
            $table->text('last_error')->nullable();
            $table->timestampTz('processed_at')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('company_id')
                ->references('id')->on('company')
                ->onDelete('restrict');

            $table->index(['company_id', 'aggregate_type', 'aggregate_id', 'created_at']);
            $table->index(['event_type', 'status']);
        });

        // Partial index for the claim query: status = pending AND available_at <= now()
        DB::statement(
            "CREATE INDEX outbox_pending_available_idx
             ON outbox (available_at)
             WHERE status = 'pending'"
        );

        // Support SKIP LOCKED claim patterns
        DB::statement(
            "CREATE INDEX outbox_status_available_idx
             ON outbox (status, available_at)"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox');
    }
};
