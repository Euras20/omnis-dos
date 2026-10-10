<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Foundation group 1 — idempotency key store for command retry safety.
 *
 * Shared IdempotencyStore reads/writes this table.
 * TTL is enforced in application code (config/omnis.php).
 *
 * Source: 02-database.md §5.7, 03-api.md, IdempotencyStore.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_key', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('key', 255);
            $table->string('request_hash', 128);
            $table->unsignedSmallInteger('status_code');
            $table->jsonb('response')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('company_id')
                ->references('id')->on('company')
                ->onDelete('restrict');

            $table->unique(['company_id', 'key']);
            $table->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_key');
    }
};
