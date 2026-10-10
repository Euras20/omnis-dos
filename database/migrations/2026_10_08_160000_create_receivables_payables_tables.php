<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration group 4/5 — Receivables + Payables.
 *
 * Source: 02-database.md §5.4–5.7, 03-api.md Receivables/Payables, 07-services-use-cases.md.
 * Tables: invoice, invoice_line, credit_note, credit_note_line,
 *         receipt, receipt_allocation, customer_refund,
 *         bill, supplier_payment, payment_allocation.
 *
 * Derived balances (invoice_open, receipt_available, bill_open) are SQL functions.
 * Sales order / PO FKs are nullable until those modules land (14.10).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ─── Receivables ───────────────────────────────────────────────────

        Schema::create('invoice', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('invoice_no', 64);
            $table->uuid('customer_id');
            $table->uuid('sales_order_id')->nullable();
            $table->date('invoice_date');
            $table->date('due_date')->nullable();
            $table->char('currency', 3)->default('GHS');
            $table->decimal('fx_rate', 20, 8)->nullable();
            $table->bigInteger('net_minor')->default(0);
            $table->bigInteger('vat_minor')->default(0);
            $table->bigInteger('nhil_minor')->default(0);
            $table->bigInteger('getfund_minor')->default(0);
            $table->bigInteger('covid_minor')->default(0);
            $table->bigInteger('tax_total_minor')->default(0);
            $table->bigInteger('gross_minor')->default(0);
            $table->uuid('tax_rule_version_id')->nullable();
            $table->jsonb('tax_snapshot')->nullable();
            $table->string('status', 32)->default('posted'); // posted | credited | void
            $table->uuid('journal_id')->nullable();
            $table->text('memo')->nullable();
            $table->uuid('created_by')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->foreign('customer_id')->references('id')->on('party')->restrictOnDelete();
            $table->foreign('journal_id')->references('id')->on('journal')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('app_user')->nullOnDelete();
            $table->unique(['company_id', 'invoice_no']);
            $table->index(['company_id', 'customer_id', 'invoice_date']);
            $table->index(['company_id', 'status', 'invoice_date']);
            $table->index(['company_id', 'due_date']);
        });

        Schema::create('invoice_line', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('invoice_id');
            $table->unsignedInteger('line_no');
            $table->uuid('item_id')->nullable();
            $table->uuid('sales_order_line_id')->nullable();
            $table->string('description', 512);
            $table->decimal('qty', 18, 3);
            $table->string('unit', 32)->default('pcs');
            $table->bigInteger('unit_price_minor');
            $table->bigInteger('net_minor');
            $table->bigInteger('vat_minor')->default(0);
            $table->bigInteger('nhil_minor')->default(0);
            $table->bigInteger('getfund_minor')->default(0);
            $table->bigInteger('covid_minor')->default(0);
            $table->bigInteger('gross_minor');
            $table->uuid('revenue_account_id')->nullable();
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->foreign('invoice_id')->references('id')->on('invoice')->restrictOnDelete();
            $table->foreign('item_id')->references('id')->on('item')->nullOnDelete();
            $table->unique(['invoice_id', 'line_no']);
            $table->index(['company_id', 'invoice_id']);
        });

        Schema::create('credit_note', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('credit_no', 64);
            $table->uuid('invoice_id');
            $table->date('credit_date');
            $table->char('currency', 3)->default('GHS');
            $table->bigInteger('net_minor')->default(0);
            $table->bigInteger('vat_minor')->default(0);
            $table->bigInteger('nhil_minor')->default(0);
            $table->bigInteger('getfund_minor')->default(0);
            $table->bigInteger('covid_minor')->default(0);
            $table->bigInteger('tax_total_minor')->default(0);
            $table->bigInteger('gross_minor')->default(0);
            $table->string('reason', 512)->nullable();
            $table->uuid('journal_id')->nullable();
            $table->uuid('created_by')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->foreign('invoice_id')->references('id')->on('invoice')->restrictOnDelete();
            $table->foreign('journal_id')->references('id')->on('journal')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('app_user')->nullOnDelete();
            $table->unique(['company_id', 'credit_no']);
            $table->index(['company_id', 'invoice_id']);
        });

        Schema::create('credit_note_line', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('credit_note_id');
            $table->uuid('invoice_line_id')->nullable();
            $table->unsignedInteger('line_no');
            $table->string('description', 512);
            $table->decimal('qty', 18, 3);
            $table->bigInteger('net_minor');
            $table->bigInteger('vat_minor')->default(0);
            $table->bigInteger('nhil_minor')->default(0);
            $table->bigInteger('getfund_minor')->default(0);
            $table->bigInteger('covid_minor')->default(0);
            $table->bigInteger('gross_minor');
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->foreign('credit_note_id')->references('id')->on('credit_note')->restrictOnDelete();
            $table->foreign('invoice_line_id')->references('id')->on('invoice_line')->nullOnDelete();
            $table->unique(['credit_note_id', 'line_no']);
        });

        Schema::create('receipt', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('receipt_no', 64);
            $table->uuid('customer_id');
            $table->date('receipt_date');
            $table->char('currency', 3)->default('GHS');
            $table->string('cash_account_code', 32);
            $table->bigInteger('cash_minor')->default(0);
            $table->bigInteger('wht_minor')->default(0);
            $table->string('wht_certificate', 128)->nullable();
            $table->bigInteger('total_minor'); // cash + wht
            $table->string('bank_ref', 128)->nullable();
            $table->text('memo')->nullable();
            $table->uuid('journal_id')->nullable();
            $table->uuid('created_by')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->foreign('customer_id')->references('id')->on('party')->restrictOnDelete();
            $table->foreign('journal_id')->references('id')->on('journal')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('app_user')->nullOnDelete();
            $table->unique(['company_id', 'receipt_no']);
            $table->unique(['company_id', 'bank_ref']);
            $table->index(['company_id', 'customer_id', 'receipt_date']);
        });

        // High-volume append-only
        Schema::create('receipt_allocation', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('company_id');
            $table->uuid('receipt_id');
            $table->uuid('invoice_id')->nullable(); // null = advance / unapplied
            $table->bigInteger('amount_minor');
            $table->date('allocated_at');
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->foreign('receipt_id')->references('id')->on('receipt')->restrictOnDelete();
            $table->foreign('invoice_id')->references('id')->on('invoice')->nullOnDelete();
            $table->index(['company_id', 'receipt_id']);
            $table->index(['company_id', 'invoice_id']);
        });

        Schema::create('customer_refund', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('receipt_id');
            $table->string('refund_no', 64);
            $table->date('refund_date');
            $table->bigInteger('amount_minor');
            $table->string('cash_account_code', 32);
            $table->string('bank_ref', 128)->nullable();
            $table->uuid('journal_id')->nullable();
            $table->uuid('created_by')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->foreign('receipt_id')->references('id')->on('receipt')->restrictOnDelete();
            $table->foreign('journal_id')->references('id')->on('journal')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('app_user')->nullOnDelete();
            $table->unique(['company_id', 'refund_no']);
            $table->index(['company_id', 'receipt_id']);
        });

        // ─── Payables ──────────────────────────────────────────────────────

        Schema::create('bill', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('bill_no', 64);
            $table->uuid('supplier_id');
            $table->string('supplier_ref', 128)->nullable();
            $table->uuid('purchase_order_id')->nullable();
            $table->date('bill_date');
            $table->date('due_date')->nullable();
            $table->char('currency', 3)->default('GHS');
            $table->bigInteger('net_minor')->default(0);
            $table->bigInteger('tax_minor')->default(0);
            $table->bigInteger('gross_minor')->default(0);
            $table->uuid('expense_account_id')->nullable();
            $table->string('status', 32)->default('posted'); // posted | void
            $table->uuid('journal_id')->nullable();
            $table->text('memo')->nullable();
            $table->uuid('created_by')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->foreign('supplier_id')->references('id')->on('party')->restrictOnDelete();
            $table->foreign('journal_id')->references('id')->on('journal')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('app_user')->nullOnDelete();
            $table->unique(['company_id', 'bill_no']);
            $table->index(['company_id', 'supplier_id', 'bill_date']);
            $table->index(['company_id', 'status', 'bill_date']);
        });

        // Partial unique: block duplicate supplier_ref per company when present
        DB::statement('
            CREATE UNIQUE INDEX bill_supplier_ref
            ON bill (company_id, supplier_id, supplier_ref)
            WHERE supplier_ref IS NOT NULL
        ');

        Schema::create('supplier_payment', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('payment_no', 64);
            $table->uuid('supplier_id');
            $table->date('payment_date')->nullable();
            $table->string('status', 32)->default('prepared'); // prepared | confirmed | cancelled
            $table->string('cash_account_code', 32)->nullable();
            $table->bigInteger('gross_minor')->default(0);
            $table->bigInteger('wht_minor')->default(0);
            $table->bigInteger('net_minor')->default(0);
            $table->string('bank_ref', 128)->nullable();
            $table->uuid('prepared_by')->nullable();
            $table->uuid('confirmed_by')->nullable();
            $table->timestampTz('confirmed_at')->nullable();
            $table->uuid('journal_id')->nullable();
            $table->text('memo')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->foreign('supplier_id')->references('id')->on('party')->restrictOnDelete();
            $table->foreign('prepared_by')->references('id')->on('app_user')->nullOnDelete();
            $table->foreign('confirmed_by')->references('id')->on('app_user')->nullOnDelete();
            $table->foreign('journal_id')->references('id')->on('journal')->nullOnDelete();
            $table->unique(['company_id', 'payment_no']);
            $table->index(['company_id', 'supplier_id', 'status']);
            $table->index(['company_id', 'status']);
        });

        // Partial unique on bank_ref when confirmed
        DB::statement('
            CREATE UNIQUE INDEX supplier_payment_bank_ref
            ON supplier_payment (company_id, bank_ref)
            WHERE bank_ref IS NOT NULL AND status = \'confirmed\'
        ');

        Schema::create('payment_allocation', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('company_id');
            $table->uuid('supplier_payment_id');
            $table->uuid('bill_id');
            $table->bigInteger('amount_minor');
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->foreign('supplier_payment_id')->references('id')->on('supplier_payment')->restrictOnDelete();
            $table->foreign('bill_id')->references('id')->on('bill')->restrictOnDelete();
            $table->unique(['supplier_payment_id', 'bill_id']);
            $table->index(['company_id', 'bill_id']);
        });

        // ─── CHECKs and derived functions ──────────────────────────────────

        // Invoice gross = net + tax components
        DB::statement('
            ALTER TABLE invoice
            ADD CONSTRAINT invoice_gross_equals_parts
            CHECK (gross_minor = net_minor + vat_minor + nhil_minor + getfund_minor + covid_minor)
        ');

        DB::statement('
            ALTER TABLE invoice
            ADD CONSTRAINT invoice_tax_total
            CHECK (tax_total_minor = vat_minor + nhil_minor + getfund_minor + covid_minor)
        ');

        // Receipt: WHT requires certificate
        DB::statement('
            ALTER TABLE receipt
            ADD CONSTRAINT receipt_wht_needs_certificate
            CHECK (wht_minor = 0 OR (wht_certificate IS NOT NULL AND length(trim(wht_certificate)) > 0))
        ');

        DB::statement('
            ALTER TABLE receipt
            ADD CONSTRAINT receipt_total
            CHECK (total_minor = cash_minor + wht_minor)
        ');

        // Confirmed payment: bank_ref, date, journal, confirmer ≠ preparer
        DB::statement('
            ALTER TABLE supplier_payment
            ADD CONSTRAINT supplier_payment_confirmed_fields
            CHECK (
                status <> \'confirmed\'
                OR (
                    bank_ref IS NOT NULL
                    AND payment_date IS NOT NULL
                    AND journal_id IS NOT NULL
                    AND confirmed_by IS NOT NULL
                    AND (prepared_by IS NULL OR confirmed_by <> prepared_by)
                )
            )
        ');

        // invoice_open(invoice_id, cutoff date) — gross − allocations − credits ≤ cutoff
        DB::statement("
            CREATE OR REPLACE FUNCTION invoice_open(p_invoice_id uuid, p_cutoff date DEFAULT CURRENT_DATE)
            RETURNS bigint
            LANGUAGE sql
            STABLE
            AS \$\$
                SELECT COALESCE(i.gross_minor, 0)
                    - COALESCE((
                        SELECT SUM(ra.amount_minor)
                        FROM receipt_allocation ra
                        JOIN receipt r ON r.id = ra.receipt_id
                        WHERE ra.invoice_id = i.id
                          AND r.receipt_date <= p_cutoff
                    ), 0)
                    - COALESCE((
                        SELECT SUM(cn.gross_minor)
                        FROM credit_note cn
                        WHERE cn.invoice_id = i.id
                          AND cn.credit_date <= p_cutoff
                    ), 0)
                FROM invoice i
                WHERE i.id = p_invoice_id
            \$\$
        ");

        // receipt_available — cash+wht − allocations − refunds
        DB::statement("
            CREATE OR REPLACE FUNCTION receipt_available(p_receipt_id uuid, p_cutoff date DEFAULT CURRENT_DATE)
            RETURNS bigint
            LANGUAGE sql
            STABLE
            AS \$\$
                SELECT COALESCE(r.total_minor, 0)
                    - COALESCE((
                        SELECT SUM(ra.amount_minor)
                        FROM receipt_allocation ra
                        WHERE ra.receipt_id = r.id
                          AND ra.invoice_id IS NOT NULL
                          AND ra.allocated_at <= p_cutoff
                    ), 0)
                    - COALESCE((
                        SELECT SUM(cr.amount_minor)
                        FROM customer_refund cr
                        WHERE cr.receipt_id = r.id
                          AND cr.refund_date <= p_cutoff
                    ), 0)
                FROM receipt r
                WHERE r.id = p_receipt_id
            \$\$
        ");

        // bill_open
        DB::statement("
            CREATE OR REPLACE FUNCTION bill_open(p_bill_id uuid, p_cutoff date DEFAULT CURRENT_DATE)
            RETURNS bigint
            LANGUAGE sql
            STABLE
            AS \$\$
                SELECT COALESCE(b.gross_minor, 0)
                    - COALESCE((
                        SELECT SUM(pa.amount_minor)
                        FROM payment_allocation pa
                        JOIN supplier_payment sp ON sp.id = pa.supplier_payment_id
                        WHERE pa.bill_id = b.id
                          AND sp.status = 'confirmed'
                          AND sp.payment_date <= p_cutoff
                    ), 0)
                FROM bill b
                WHERE b.id = p_bill_id
            \$\$
        ");

        // Append-only triggers for allocations and refunds
        DB::statement("
            CREATE OR REPLACE FUNCTION trg_append_only()
            RETURNS trigger
            LANGUAGE plpgsql
            AS \$\$
            BEGIN
                RAISE EXCEPTION 'Table % is append-only; updates and deletes are not allowed', TG_TABLE_NAME;
            END;
            \$\$
        ");

        DB::statement('
            CREATE TRIGGER receipt_allocation_append_only
            BEFORE UPDATE OR DELETE ON receipt_allocation
            FOR EACH ROW EXECUTE FUNCTION trg_append_only()
        ');

        DB::statement('
            CREATE TRIGGER payment_allocation_append_only
            BEFORE UPDATE OR DELETE ON payment_allocation
            FOR EACH ROW EXECUTE FUNCTION trg_append_only()
        ');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS payment_allocation_append_only ON payment_allocation');
        DB::statement('DROP TRIGGER IF EXISTS receipt_allocation_append_only ON receipt_allocation');
        DB::statement('DROP FUNCTION IF EXISTS trg_append_only()');
        DB::statement('DROP FUNCTION IF EXISTS bill_open(uuid, date)');
        DB::statement('DROP FUNCTION IF EXISTS receipt_available(uuid, date)');
        DB::statement('DROP FUNCTION IF EXISTS invoice_open(uuid, date)');

        Schema::dropIfExists('payment_allocation');
        Schema::dropIfExists('supplier_payment');
        Schema::dropIfExists('bill');
        Schema::dropIfExists('customer_refund');
        Schema::dropIfExists('receipt_allocation');
        Schema::dropIfExists('receipt');
        Schema::dropIfExists('credit_note_line');
        Schema::dropIfExists('credit_note');
        Schema::dropIfExists('invoice_line');
        Schema::dropIfExists('invoice');
    }
};
