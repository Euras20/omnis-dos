<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration group 7 — Payroll & Assets.
 *
 * Source: 02-database.md §5.4–5.7, 03-api.md Payroll / Assets / Loans.
 * Tables: employee, payroll_rule_version, payroll_run, payroll_line,
 *         asset_class, fixed_asset, depreciation_run, depreciation_line,
 *         loan, loan_instalment.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ─── Payroll ───────────────────────────────────────────────────────

        Schema::create('employee', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('employee_no', 64);
            $table->string('first_name', 128);
            $table->string('last_name', 128);
            $table->string('other_names', 128)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->date('hire_date');
            $table->date('termination_date')->nullable();
            $table->string('ssnit_number', 64)->nullable();
            $table->string('tin', 64)->nullable();
            $table->string('bank_account', 64)->nullable();
            $table->string('bank_name', 128)->nullable();
            $table->string('department', 128)->nullable();
            $table->string('job_title', 128)->nullable();
            $table->bigInteger('basic_minor')->default(0);
            $table->char('currency', 3)->default('GHS');
            $table->boolean('is_active')->default(true);
            $table->uuid('party_id')->nullable();
            $table->uuid('user_id')->nullable();
            $table->unsignedInteger('row_version')->default(1);
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->foreign('party_id')->references('id')->on('party')->nullOnDelete();
            $table->foreign('user_id')->references('id')->on('app_user')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('app_user')->nullOnDelete();
            $table->unique(['company_id', 'employee_no']);
            $table->index(['company_id', 'is_active']);
        });

        Schema::create('payroll_rule_version', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            // PAYE bands, SSNIT rates, overtime multipliers as JSON
            $table->jsonb('rules');
            $table->uuid('approved_by')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->foreign('approved_by')->references('id')->on('app_user')->nullOnDelete();
            $table->unique(['company_id', 'effective_from']);
            $table->index(['company_id', 'effective_from', 'effective_to']);
        });

        Schema::create('payroll_run', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('run_no', 64);
            // YYYY-MM
            $table->string('period_month', 7);
            $table->date('pay_date')->nullable();
            // draft | approved | posted | paid
            $table->string('status', 16)->default('draft');
            $table->uuid('rule_version_id')->nullable();
            $table->bigInteger('gross_total_minor')->default(0);
            $table->bigInteger('ssf_employee_minor')->default(0);
            $table->bigInteger('ssf_employer_minor')->default(0);
            $table->bigInteger('paye_minor')->default(0);
            $table->bigInteger('net_total_minor')->default(0);
            $table->uuid('journal_id')->nullable();
            $table->uuid('payment_journal_id')->nullable();
            $table->string('bank_ref', 128)->nullable();
            $table->uuid('prepared_by')->nullable();
            $table->uuid('approved_by')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->uuid('posted_by')->nullable();
            $table->timestampTz('posted_at')->nullable();
            $table->uuid('paid_by')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->foreign('rule_version_id')->references('id')->on('payroll_rule_version')->nullOnDelete();
            $table->foreign('journal_id')->references('id')->on('journal')->nullOnDelete();
            $table->foreign('payment_journal_id')->references('id')->on('journal')->nullOnDelete();
            $table->foreign('prepared_by')->references('id')->on('app_user')->nullOnDelete();
            $table->foreign('approved_by')->references('id')->on('app_user')->nullOnDelete();
            $table->foreign('posted_by')->references('id')->on('app_user')->nullOnDelete();
            $table->foreign('paid_by')->references('id')->on('app_user')->nullOnDelete();
            $table->unique(['company_id', 'run_no']);
            $table->unique(['company_id', 'period_month']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('payroll_line', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('payroll_run_id');
            $table->uuid('employee_id');
            $table->unsignedInteger('line_no');
            $table->bigInteger('basic_minor')->default(0);
            $table->bigInteger('overtime_hours')->default(0);
            $table->bigInteger('overtime_minor')->default(0);
            $table->bigInteger('allowances_minor')->default(0);
            $table->bigInteger('gross_minor')->default(0);
            $table->bigInteger('ssf_employee_minor')->default(0);
            $table->bigInteger('ssf_employer_minor')->default(0);
            $table->bigInteger('paye_minor')->default(0);
            $table->bigInteger('other_deductions_minor')->default(0);
            $table->bigInteger('net_minor')->default(0);
            $table->boolean('is_frozen')->default(false);
            $table->jsonb('calculation_snapshot')->nullable();
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->foreign('payroll_run_id')->references('id')->on('payroll_run')->restrictOnDelete();
            $table->foreign('employee_id')->references('id')->on('employee')->restrictOnDelete();
            $table->unique(['payroll_run_id', 'employee_id']);
            $table->unique(['payroll_run_id', 'line_no']);
            $table->index(['company_id', 'payroll_run_id']);
        });

        // Prevent mutation of frozen payroll lines
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION payroll_line_frozen_guard()
RETURNS trigger AS $$
BEGIN
  IF OLD.is_frozen THEN
    RAISE EXCEPTION 'payroll_line % is frozen and cannot be modified', OLD.id;
  END IF;
  RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_payroll_line_frozen
BEFORE UPDATE OR DELETE ON payroll_line
FOR EACH ROW EXECUTE FUNCTION payroll_line_frozen_guard();
SQL);

        // ─── Assets ────────────────────────────────────────────────────────

        Schema::create('asset_class', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('code', 32);
            $table->string('name', 255);
            // straight_line | reducing_balance
            $table->string('method', 32)->default('straight_line');
            $table->unsignedInteger('useful_life_months')->default(60);
            $table->bigInteger('residual_pct')->default(0); // basis points or percent * 100
            $table->uuid('asset_account_id')->nullable();
            $table->uuid('accum_depr_account_id')->nullable();
            $table->uuid('depr_expense_account_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('row_version')->default(1);
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->foreign('asset_account_id')->references('id')->on('account')->nullOnDelete();
            $table->foreign('accum_depr_account_id')->references('id')->on('account')->nullOnDelete();
            $table->foreign('depr_expense_account_id')->references('id')->on('account')->nullOnDelete();
            $table->unique(['company_id', 'code']);
        });

        Schema::create('fixed_asset', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('asset_no', 64);
            $table->string('name', 255);
            $table->uuid('asset_class_id');
            $table->date('acquisition_date');
            $table->bigInteger('cost_minor');
            $table->bigInteger('residual_minor')->default(0);
            $table->unsignedInteger('useful_life_months');
            $table->string('method', 32)->default('straight_line');
            $table->uuid('source_bill_id')->nullable();
            $table->uuid('opening_batch_id')->nullable();
            $table->uuid('location_id')->nullable();
            // active | disposed
            $table->string('status', 16)->default('active');
            $table->date('disposal_date')->nullable();
            $table->bigInteger('disposal_proceeds_minor')->nullable();
            $table->uuid('disposal_journal_id')->nullable();
            $table->text('memo')->nullable();
            $table->unsignedInteger('row_version')->default(1);
            $table->uuid('created_by')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->foreign('asset_class_id')->references('id')->on('asset_class')->restrictOnDelete();
            $table->foreign('source_bill_id')->references('id')->on('bill')->nullOnDelete();
            $table->foreign('location_id')->references('id')->on('location')->nullOnDelete();
            $table->foreign('disposal_journal_id')->references('id')->on('journal')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('app_user')->nullOnDelete();
            $table->unique(['company_id', 'asset_no']);
            // One asset per source bill when linked
            $table->unique(['company_id', 'source_bill_id'], 'fixed_asset_one_per_bill');
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'asset_class_id']);
        });

        Schema::create('depreciation_run', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('period_month', 7); // YYYY-MM
            $table->string('status', 16)->default('posted');
            $table->bigInteger('total_charge_minor')->default(0);
            $table->uuid('journal_id')->nullable();
            $table->uuid('posted_by')->nullable();
            $table->timestampTz('posted_at')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->foreign('journal_id')->references('id')->on('journal')->nullOnDelete();
            $table->foreign('posted_by')->references('id')->on('app_user')->nullOnDelete();
            $table->unique(['company_id', 'period_month']);
        });

        Schema::create('depreciation_line', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('company_id');
            $table->uuid('depreciation_run_id');
            $table->uuid('fixed_asset_id');
            $table->bigInteger('charge_minor');
            $table->bigInteger('accum_before_minor')->default(0);
            $table->bigInteger('accum_after_minor')->default(0);
            $table->bigInteger('nbv_after_minor')->default(0);
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->foreign('depreciation_run_id')->references('id')->on('depreciation_run')->restrictOnDelete();
            $table->foreign('fixed_asset_id')->references('id')->on('fixed_asset')->restrictOnDelete();
            $table->unique(['depreciation_run_id', 'fixed_asset_id']);
            $table->index(['company_id', 'fixed_asset_id']);
        });

        // Append-only depreciation lines
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION depreciation_line_immutable()
RETURNS trigger AS $$
BEGIN
  RAISE EXCEPTION 'depreciation_line is append-only';
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_depreciation_line_no_update
BEFORE UPDATE OR DELETE ON depreciation_line
FOR EACH ROW EXECUTE FUNCTION depreciation_line_immutable();
SQL);

        // ─── Loans ─────────────────────────────────────────────────────────

        Schema::create('loan', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('loan_no', 64);
            $table->string('lender_name', 255);
            $table->uuid('lender_party_id')->nullable();
            $table->date('disbursement_date');
            $table->bigInteger('principal_minor');
            $table->decimal('annual_rate_pct', 8, 4)->default(0);
            $table->unsignedInteger('term_months');
            $table->char('currency', 3)->default('GHS');
            $table->uuid('bank_account_id')->nullable(); // COA cash/bank account
            $table->uuid('liability_account_id')->nullable();
            $table->uuid('interest_account_id')->nullable();
            $table->uuid('disbursement_journal_id')->nullable();
            // active | settled | defaulted
            $table->string('status', 16)->default('active');
            $table->text('memo')->nullable();
            $table->uuid('created_by')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->foreign('lender_party_id')->references('id')->on('party')->nullOnDelete();
            $table->foreign('bank_account_id')->references('id')->on('account')->nullOnDelete();
            $table->foreign('liability_account_id')->references('id')->on('account')->nullOnDelete();
            $table->foreign('interest_account_id')->references('id')->on('account')->nullOnDelete();
            $table->foreign('disbursement_journal_id')->references('id')->on('journal')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('app_user')->nullOnDelete();
            $table->unique(['company_id', 'loan_no']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('loan_instalment', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('loan_id');
            $table->unsignedInteger('instalment_no');
            $table->date('due_date');
            $table->bigInteger('principal_minor');
            $table->bigInteger('interest_minor');
            $table->bigInteger('total_minor');
            // scheduled | paid | waived
            $table->string('status', 16)->default('scheduled');
            $table->date('paid_date')->nullable();
            $table->uuid('payment_journal_id')->nullable();
            $table->string('bank_ref', 128)->nullable();
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->foreign('loan_id')->references('id')->on('loan')->restrictOnDelete();
            $table->foreign('payment_journal_id')->references('id')->on('journal')->nullOnDelete();
            $table->unique(['loan_id', 'instalment_no']);
            $table->index(['company_id', 'loan_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_instalment');
        Schema::dropIfExists('loan');
        Schema::dropIfExists('depreciation_line');
        Schema::dropIfExists('depreciation_run');
        Schema::dropIfExists('fixed_asset');
        Schema::dropIfExists('asset_class');
        Schema::dropIfExists('payroll_line');
        Schema::dropIfExists('payroll_run');
        Schema::dropIfExists('payroll_rule_version');
        Schema::dropIfExists('employee');
        DB::unprepared('DROP FUNCTION IF EXISTS payroll_line_frozen_guard() CASCADE');
        DB::unprepared('DROP FUNCTION IF EXISTS depreciation_line_immutable() CASCADE');
    }
};
