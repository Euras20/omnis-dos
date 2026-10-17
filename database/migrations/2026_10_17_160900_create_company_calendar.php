<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 16.9 — Company calendars (working days, shifts, holidays) and tax filings.
 *
 * Company already carries fiscal_year_start_month. This adds the operational
 * calendar the Settings → Calendars screen and the tax filing calendar consume.
 * Defaults match the frontend demo (Mon–Sat factory, Mon–Fri office, sample
 * Ghana holidays, sample filings).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_calendar', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->unique();
            $table->jsonb('working_days_factory')->nullable();
            $table->jsonb('working_days_office')->nullable();
            $table->jsonb('shifts')->nullable();
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
        });

        Schema::create('holiday', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->date('holiday_date');
            $table->string('name', 255);
            $table->unsignedSmallInteger('year');
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->unique(['company_id', 'holiday_date']);
            $table->index(['company_id', 'year']);
        });

        Schema::create('tax_filing', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('code', 64);
            $table->string('tax', 128);
            $table->string('period', 64);
            $table->date('due_date');
            $table->unsignedInteger('revision')->default(0);
            $table->string('filing_status', 32)->default('Not started');
            $table->string('payment_status', 32)->default('Not paid');
            $table->string('submission_ref', 255)->nullable();
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_filing');
        Schema::dropIfExists('holiday');
        Schema::dropIfExists('company_calendar');
    }
};
