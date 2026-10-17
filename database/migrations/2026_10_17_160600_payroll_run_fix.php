<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 16.6 — Payroll run fix.
 *
 * employee: level + resident flag so Ghana junior/non-resident rules and display work.
 * payroll_run: exceptions (OT over monthly cap) + ot_tax_total_minor.
 * payroll_line: ot_tax_minor (junior overtime tax, separate from PAYE).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee', function (Blueprint $table) {
            $table->string('level', 64)->nullable()->after('job_title');
            $table->boolean('is_resident')->default(true)->after('level');
        });

        Schema::table('payroll_run', function (Blueprint $table) {
            $table->bigInteger('ot_tax_total_minor')->default(0)->after('paye_minor');
            $table->jsonb('exceptions')->nullable()->after('net_total_minor');
        });

        Schema::table('payroll_line', function (Blueprint $table) {
            $table->bigInteger('ot_tax_minor')->default(0)->after('paye_minor');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_line', function (Blueprint $table) {
            $table->dropColumn('ot_tax_minor');
        });

        Schema::table('payroll_run', function (Blueprint $table) {
            $table->dropColumn(['ot_tax_total_minor', 'exceptions']);
        });

        Schema::table('employee', function (Blueprint $table) {
            $table->dropColumn(['level', 'is_resident']);
        });
    }
};
