<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration group 8/9 — Banking, cash, period control, supporting.
 *
 * Source: 02-database.md, 03-api.md Treasury / Close / Opening / Files / Reports.
 * Tables: bank_import, bank_statement_line, bank_reconciliation,
 *         cash_expense, cash_expense_line, cash_transfer,
 *         close_waiver, opening_batch, opening_row, opening_posted_key,
 *         document_file, saved_report, report_favourite, performance_layout.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ─── Treasury ──────────────────────────────────────────────────────

        Schema::create('bank_import', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('bank_account_id'); // COA account for the bank
            $table->string('file_name', 255)->nullable();
            $table->string('file_hash', 128);
            $table->jsonb('column_mapping')->nullable();
            $table->unsignedInteger('line_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->uuid('imported_by')->nullable();
            $table->timestampTz('imported_at')->useCurrent();
            $table->string('request_id', 64)->nullable();
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->foreign('bank_account_id')->references('id')->on('account')->restrictOnDelete();
            $table->foreign('imported_by')->references('id')->on('app_user')->nullOnDelete();
            $table->unique(['company_id', 'file_hash']);
            $table->index(['company_id', 'bank_account_id']);
        });

        Schema::create('bank_statement_line', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('company_id');
            $table->uuid('bank_import_id');
            $table->uuid('bank_account_id');
            $table->date('txn_date');
            $table->string('reference', 255)->nullable();
            $table->string('description', 512)->nullable();
            $table->bigInteger('amount_minor'); // signed: credit positive, debit negative convention or absolute + direction
            $table->string('direction', 8); // credit | debit
            $table->char('currency', 3)->default('GHS');
            // unmatched | matched | excluded | booked
            $table->string('status', 16)->default('unmatched');
            $table->uuid('matched_journal_id')->nullable();
            $table->uuid('matched_by')->nullable();
            $table->timestampTz('matched_at')->nullable();
            $table->string('exclude_reason', 255)->nullable();
            $table->string('line_hash', 128); // for exact-duplicate skip
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->foreign('bank_import_id')->references('id')->on('bank_import')->restrictOnDelete();
            $table->foreign('bank_account_id')->references('id')->on('account')->restrictOnDelete();
            $table->foreign('matched_journal_id')->references('id')->on('journal')->nullOnDelete();
            $table->foreign('matched_by')->references('id')->on('app_user')->nullOnDelete();
            $table->unique(['company_id', 'line_hash']);
            $table->unique(['matched_journal_id'], 'bank_line_match_once');
            $table->index(['company_id', 'bank_account_id', 'status', 'txn_date']);
            $table->index(['company_id', 'txn_date']);
        });

        Schema::create('bank_reconciliation', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('bank_account_id');
            $table->date('statement_date');
            $table->bigInteger('statement_closing_minor');
            $table->bigInteger('book_balance_minor')->default(0);
            $table->bigInteger('difference_minor')->default(0);
            // prepared | approved
            $table->string('status', 16)->default('prepared');
            $table->uuid('prepared_by')->nullable();
            $table->timestampTz('prepared_at')->nullable();
            $table->uuid('approved_by')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->jsonb('snapshot')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->foreign('bank_account_id')->references('id')->on('account')->restrictOnDelete();
            $table->foreign('prepared_by')->references('id')->on('app_user')->nullOnDelete();
            $table->foreign('approved_by')->references('id')->on('app_user')->nullOnDelete();
            $table->index(['company_id', 'bank_account_id', 'statement_date']);
        });

        // Difference must be zero on approve (app + CHECK-style enforcement via trigger)
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION bank_reconciliation_approve_guard()
RETURNS trigger AS $$
BEGIN
  IF NEW.status = 'approved' AND NEW.difference_minor <> 0 THEN
    RAISE EXCEPTION 'bank_reconciliation difference must be zero to approve';
  END IF;
  IF NEW.status = 'approved' AND NEW.approved_by IS NOT NULL
     AND NEW.prepared_by IS NOT NULL AND NEW.approved_by = NEW.prepared_by THEN
    RAISE EXCEPTION 'bank_reconciliation approver must differ from preparer';
  END IF;
  RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_bank_reconciliation_approve
BEFORE UPDATE ON bank_reconciliation
FOR EACH ROW EXECUTE FUNCTION bank_reconciliation_approve_guard();
SQL);

        Schema::create('cash_expense', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('expense_no', 64);
            $table->date('expense_date');
            // bank | momo | petty_cash
            $table->string('paid_from', 32);
            $table->uuid('paid_from_account_id');
            $table->string('payee', 255)->nullable();
            $table->string('method', 32)->nullable(); // cheque | transfer | cash | momo
            $table->string('cheque_no', 64)->nullable();
            $table->char('currency', 3)->default('GHS');
            $table->bigInteger('gross_minor')->default(0);
            $table->bigInteger('tax_minor')->default(0);
            $table->bigInteger('net_minor')->default(0);
            $table->uuid('journal_id')->nullable();
            $table->text('memo')->nullable();
            $table->uuid('created_by')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->foreign('paid_from_account_id')->references('id')->on('account')->restrictOnDelete();
            $table->foreign('journal_id')->references('id')->on('journal')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('app_user')->nullOnDelete();
            $table->unique(['company_id', 'expense_no']);
            $table->index(['company_id', 'expense_date']);
        });

        Schema::create('cash_expense_line', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('cash_expense_id');
            $table->unsignedInteger('line_no');
            $table->uuid('expense_account_id');
            $table->string('description', 512);
            $table->bigInteger('amount_minor');
            $table->bigInteger('tax_minor')->default(0);
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->foreign('cash_expense_id')->references('id')->on('cash_expense')->restrictOnDelete();
            $table->foreign('expense_account_id')->references('id')->on('account')->restrictOnDelete();
            $table->unique(['cash_expense_id', 'line_no']);
        });

        Schema::create('cash_transfer', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('transfer_no', 64);
            $table->date('transfer_date');
            $table->uuid('from_account_id');
            $table->uuid('to_account_id');
            $table->bigInteger('amount_minor');
            $table->char('currency', 3)->default('GHS');
            $table->uuid('journal_id')->nullable();
            $table->text('memo')->nullable();
            $table->uuid('created_by')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->foreign('from_account_id')->references('id')->on('account')->restrictOnDelete();
            $table->foreign('to_account_id')->references('id')->on('account')->restrictOnDelete();
            $table->foreign('journal_id')->references('id')->on('journal')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('app_user')->nullOnDelete();
            $table->unique(['company_id', 'transfer_no']);
            $table->index(['company_id', 'transfer_date']);
        });

        // ─── Period control / Controls ─────────────────────────────────────

        Schema::create('close_waiver', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('period_month', 7);
            $table->string('exception_code', 64);
            $table->text('reason');
            $table->string('evidence_ref', 255)->nullable();
            $table->uuid('waived_by')->nullable();
            $table->timestampTz('waived_at')->useCurrent();
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->foreign('waived_by')->references('id')->on('app_user')->nullOnDelete();
            $table->unique(['company_id', 'period_month', 'exception_code']);
        });

        Schema::create('opening_batch', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->date('cutover_date');
            // draft | posted | deleted
            $table->string('status', 16)->default('draft');
            $table->uuid('journal_id')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('posted_by')->nullable();
            $table->timestampTz('posted_at')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->foreign('journal_id')->references('id')->on('journal')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('app_user')->nullOnDelete();
            $table->foreign('posted_by')->references('id')->on('app_user')->nullOnDelete();
            $table->unique(['company_id', 'cutover_date']);
        });

        Schema::create('opening_row', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('opening_batch_id');
            // ar | ap | advance | bank | stock | asset | loan | trial_balance
            $table->string('row_type', 32);
            $table->unsignedInteger('row_no');
            $table->jsonb('payload');
            // pending | accepted | rejected
            $table->string('status', 16)->default('pending');
            $table->text('reject_reason')->nullable();
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->foreign('opening_batch_id')->references('id')->on('opening_batch')->restrictOnDelete();
            $table->unique(['opening_batch_id', 'row_type', 'row_no']);
            $table->index(['company_id', 'opening_batch_id', 'row_type']);
        });

        Schema::create('opening_posted_key', function (Blueprint $table) {
            $table->string('posted_key', 191)->primary();
            $table->uuid('company_id');
            $table->uuid('opening_batch_id');
            $table->timestampTz('posted_at')->useCurrent();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->foreign('opening_batch_id')->references('id')->on('opening_batch')->restrictOnDelete();
            $table->index(['company_id']);
        });

        // ─── Platform / Reporting support ──────────────────────────────────

        Schema::create('document_file', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('original_name', 255);
            $table->string('storage_key', 512);
            $table->string('mime_type', 128)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            // pending | clean | blocked
            $table->string('scan_status', 16)->default('pending');
            $table->string('entity_type', 64)->nullable();
            $table->uuid('entity_id')->nullable();
            $table->uuid('uploaded_by')->nullable();
            $table->timestampTz('uploaded_at')->useCurrent();
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->foreign('uploaded_by')->references('id')->on('app_user')->nullOnDelete();
            $table->index(['company_id', 'entity_type', 'entity_id']);
            $table->index(['company_id', 'scan_status']);
        });

        Schema::create('saved_report', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('owner_id');
            $table->string('name', 255);
            $table->string('report_key', 128);
            $table->jsonb('settings')->nullable();
            $table->boolean('is_shared')->default(false);
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->foreign('owner_id')->references('id')->on('app_user')->restrictOnDelete();
            $table->index(['company_id', 'owner_id']);
        });

        Schema::create('report_favourite', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('user_id');
            $table->string('report_key', 128);
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->foreign('user_id')->references('id')->on('app_user')->restrictOnDelete();
            $table->unique(['company_id', 'user_id', 'report_key']);
        });

        Schema::create('performance_layout', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('user_id');
            $table->jsonb('layout');
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->foreign('user_id')->references('id')->on('app_user')->restrictOnDelete();
            $table->unique(['company_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_layout');
        Schema::dropIfExists('report_favourite');
        Schema::dropIfExists('saved_report');
        Schema::dropIfExists('document_file');
        Schema::dropIfExists('opening_posted_key');
        Schema::dropIfExists('opening_row');
        Schema::dropIfExists('opening_batch');
        Schema::dropIfExists('close_waiver');
        Schema::dropIfExists('cash_transfer');
        Schema::dropIfExists('cash_expense_line');
        Schema::dropIfExists('cash_expense');
        Schema::dropIfExists('bank_reconciliation');
        Schema::dropIfExists('bank_statement_line');
        Schema::dropIfExists('bank_import');
        DB::unprepared('DROP FUNCTION IF EXISTS bank_reconciliation_approve_guard() CASCADE');
    }
};
