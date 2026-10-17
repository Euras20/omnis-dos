<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 16.4 — Nonconformances and Corrective actions (CAPA).
 *
 * nonconformance: problems found by QC (quarantine/reject) or raised manually.
 * corrective_action: CAPA linked to an NCR (send-to-CA with comments).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nonconformance', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('ncr_no', 64);
            $table->string('source_type', 32)->default('manual'); // qc_line | quarantine | manual
            $table->uuid('source_id')->nullable();
            $table->uuid('item_id')->nullable();
            $table->string('sku', 64)->nullable();
            $table->decimal('qty', 15, 3)->default(0);
            $table->text('issue');
            $table->text('cause')->nullable();
            $table->text('action')->nullable();
            $table->uuid('owner_id')->nullable();
            $table->string('status', 16)->default('open'); // open | closed | cancelled
            $table->date('due_date')->nullable();
            $table->date('occurred_at')->nullable();
            $table->uuid('corrective_action_id')->nullable();
            $table->unsignedInteger('row_version')->default(1);
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->foreign('item_id')->references('id')->on('item')->nullOnDelete();
            $table->foreign('owner_id')->references('id')->on('app_user')->nullOnDelete();
            $table->unique(['company_id', 'ncr_no']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'item_id']);
        });

        Schema::create('corrective_action', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('capa_no', 64);
            $table->uuid('nonconformance_id');
            $table->text('action');
            $table->text('comments')->nullable();
            $table->uuid('owner_id')->nullable();
            $table->uuid('verifier_id')->nullable();
            $table->string('status', 16)->default('open'); // open | in_progress | verified | closed
            $table->date('due_date')->nullable();
            $table->unsignedInteger('row_version')->default(1);
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->foreign('nonconformance_id')->references('id')->on('nonconformance')->restrictOnDelete();
            $table->foreign('owner_id')->references('id')->on('app_user')->nullOnDelete();
            $table->foreign('verifier_id')->references('id')->on('app_user')->nullOnDelete();
            $table->unique(['company_id', 'capa_no']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'nonconformance_id']);
        });

        // Add the back-reference after both tables exist.
        Schema::table('nonconformance', function (Blueprint $table) {
            $table->foreign('corrective_action_id')->references('id')->on('corrective_action')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('nonconformance', function (Blueprint $table) {
            $table->dropForeign(['corrective_action_id']);
        });
        Schema::dropIfExists('corrective_action');
        Schema::dropIfExists('nonconformance');
    }
};
