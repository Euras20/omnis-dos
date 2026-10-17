<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 16.1 — Leave requests.
 *
 * leave_request: employee-scoped leave applications.
 * Status: pending | approved | rejected | ongoing | expired
 * Balance is computed from employee entitlement minus approved days.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee', function (Blueprint $table) {
            $table->unsignedInteger('annual_leave_entitlement')->default(21)->after('is_active');
        });

        Schema::create('leave_request', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('request_no', 64);
            $table->uuid('employee_id');
            $table->string('leave_type', 32)->default('Annual'); // Annual | Sick | Casual | Unpaid
            $table->date('from_date');
            $table->date('to_date');
            $table->unsignedInteger('days');
            $table->string('status', 16)->default('pending'); // pending | approved | rejected | ongoing | expired
            $table->text('reason')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->uuid('requested_by');
            $table->uuid('decided_by')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->unsignedInteger('row_version')->default(1);
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->foreign('employee_id')->references('id')->on('employee')->restrictOnDelete();
            $table->foreign('requested_by')->references('id')->on('app_user')->restrictOnDelete();
            $table->foreign('decided_by')->references('id')->on('app_user')->nullOnDelete();
            $table->unique(['company_id', 'request_no']);
            $table->index(['company_id', 'employee_id', 'status']);
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_request');
        Schema::table('employee', function (Blueprint $table) {
            $table->dropColumn('annual_leave_entitlement');
        });
    }
};
