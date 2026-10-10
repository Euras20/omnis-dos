<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Evidence table for outbound email side-effects.
 *
 * Written by EmailSendHandler (outbox consumer). Not the legal audit trail.
 * Source: 09-events-jobs-queues.md §9.3 / §9.6, 02-database.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_message', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('to_address', 320);
            $table->string('cc_address', 320)->nullable();
            $table->string('subject', 512);
            $table->string('template_key', 128)->nullable();
            $table->string('entity_type', 64)->nullable();
            $table->uuid('entity_id')->nullable();
            // queued | sent | failed | skipped
            $table->string('status', 16)->default('queued');
            $table->string('provider_message_id', 255)->nullable();
            $table->text('last_error')->nullable();
            $table->uuid('document_file_id')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->string('idempotency_key', 191)->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->foreign('document_file_id')->references('id')->on('document_file')->nullOnDelete();
            $table->index(['company_id', 'entity_type', 'entity_id']);
            $table->index(['company_id', 'status']);
            // Idempotency for at-least-once handler retries
            $table->unique(['company_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_message');
    }
};
