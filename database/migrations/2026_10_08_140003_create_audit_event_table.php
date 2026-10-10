<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Foundation group 1 — append-only business audit trail.
 *
 * Written inside the same command transaction as the business change
 * (08-transactions, 10-auditability). Shared AuditWriter inserts UUIDs.
 *
 * Source: 02-database.md, 10-auditability-and-logging.md §10.3.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_event', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('actor_id')->nullable();
            $table->string('action', 128);
            $table->string('entity_type', 64);
            $table->uuid('entity_id')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->jsonb('detail')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('company_id')
                ->references('id')->on('company')
                ->onDelete('restrict');
            // actor_id may reference a system actor or a deleted user; no hard FK

            $table->index(['company_id', 'created_at']);
            $table->index(['company_id', 'entity_type', 'entity_id', 'created_at']);
            $table->index(['company_id', 'actor_id', 'created_at']);
            $table->index(['company_id', 'action', 'created_at']);
            $table->index('request_id');
        });

        // Append-only: reject UPDATE and DELETE
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION trg_audit_event_append_only()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION 'audit_event is append-only; % not allowed', TG_OP
                    USING ERRCODE = 'integrity_constraint_violation';
                RETURN NULL;
            END;
            $$;
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER audit_event_append_only
            BEFORE UPDATE OR DELETE ON audit_event
            FOR EACH ROW
            EXECUTE PROCEDURE trg_audit_event_append_only();
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS audit_event_append_only ON audit_event');
        DB::statement('DROP FUNCTION IF EXISTS trg_audit_event_append_only()');
        Schema::dropIfExists('audit_event');
    }
};
