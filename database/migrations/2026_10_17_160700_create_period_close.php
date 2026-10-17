<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Period close snapshot table.
 *
 * PeriodLockChecker already looks for rows with status = closed covering a date.
 * The company.period_lock_date watermark remains the primary lock; this table
 * stores the close snapshot (checks, waivers count, approver, time) and is the
 * secondary source the checker consults.
 *
 * close_waiver already exists (exception_code unique per company + period_month).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('period_close', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            // YYYY-MM
            $table->string('period_month', 7);
            $table->date('period_start');
            $table->date('period_end');
            // open | closed | reopened
            $table->string('status', 16)->default('open');
            $table->date('locked_through')->nullable();
            $table->jsonb('checks_snapshot')->nullable();
            $table->unsignedInteger('waived_count')->default(0);
            $table->uuid('locked_by')->nullable();
            $table->timestampTz('locked_at')->nullable();
            $table->uuid('reopened_by')->nullable();
            $table->timestampTz('reopened_at')->nullable();
            $table->text('reopen_reason')->nullable();
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->foreign('locked_by')->references('id')->on('app_user')->nullOnDelete();
            $table->foreign('reopened_by')->references('id')->on('app_user')->nullOnDelete();
            $table->unique(['company_id', 'period_month']);
            $table->index(['company_id', 'status', 'period_start', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('period_close');
    }
};
