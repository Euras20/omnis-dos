<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration group 6 — Sales + Procurement.
 *
 * Source: 02-database.md §5.4, 03-api.md Sales/Purchasing, 07-services-use-cases.md §7.5.3–7.5.4.
 * Tables: quotation, quotation_line, sales_order, sales_order_line,
 *         purchase_order, purchase_order_line, goods_receipt, goods_receipt_line.
 *
 * After this migration, FK constraints from invoice/stock_movement/stock_assignment
 * onto sales_order(_line) and goods_receipt_line can be added (optional tighten).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ─── Quotations ────────────────────────────────────────────────────

        Schema::create('quotation', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('quotation_no', 64);
            $table->uuid('customer_id');
            $table->date('quotation_date');
            $table->date('valid_until')->nullable();
            $table->char('currency', 3)->default('GHS');
            $table->decimal('fx_rate', 20, 8)->nullable();
            $table->string('status', 32)->default('draft');
            // draft | sent | accepted | declined | expired | converted
            $table->bigInteger('net_minor')->default(0);
            $table->bigInteger('tax_total_minor')->default(0);
            $table->bigInteger('gross_minor')->default(0);
            $table->uuid('tax_rule_version_id')->nullable();
            $table->jsonb('tax_snapshot')->nullable();
            $table->text('memo')->nullable();
            $table->uuid('converted_sales_order_id')->nullable();
            $table->uuid('created_by')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->foreign('customer_id')->references('id')->on('party')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('app_user')->nullOnDelete();
            $table->unique(['company_id', 'quotation_no']);
            $table->index(['company_id', 'customer_id', 'quotation_date']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('quotation_line', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('quotation_id');
            $table->unsignedInteger('line_no');
            $table->uuid('item_id')->nullable();
            $table->string('description', 512);
            $table->decimal('qty', 18, 3);
            $table->string('unit', 32)->default('pcs');
            $table->bigInteger('unit_price_minor');
            $table->bigInteger('net_minor');
            $table->bigInteger('tax_minor')->default(0);
            $table->bigInteger('gross_minor');
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->foreign('quotation_id')->references('id')->on('quotation')->restrictOnDelete();
            $table->foreign('item_id')->references('id')->on('item')->nullOnDelete();
            $table->unique(['quotation_id', 'line_no']);
            $table->index(['company_id', 'quotation_id']);
        });

        // ─── Sales orders ──────────────────────────────────────────────────

        Schema::create('sales_order', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('order_no', 64);
            $table->uuid('customer_id');
            $table->uuid('quotation_id')->nullable();
            $table->string('customer_po_ref', 128)->nullable();
            $table->uuid('order_type_id')->nullable();
            $table->date('order_date');
            $table->date('required_date')->nullable();
            $table->char('currency', 3)->default('GHS');
            $table->decimal('fx_rate', 20, 8)->nullable();
            $table->string('status', 32)->default('draft');
            // draft | confirmed | cancelled | closed
            $table->bigInteger('net_minor')->default(0);
            $table->bigInteger('tax_total_minor')->default(0);
            $table->bigInteger('gross_minor')->default(0);
            $table->uuid('tax_rule_version_id')->nullable();
            $table->jsonb('tax_snapshot')->nullable();
            $table->text('memo')->nullable();
            $table->text('deliver_to')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('confirmed_by')->nullable();
            $table->timestampTz('confirmed_at')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->foreign('customer_id')->references('id')->on('party')->restrictOnDelete();
            $table->foreign('quotation_id')->references('id')->on('quotation')->nullOnDelete();
            $table->foreign('order_type_id')->references('id')->on('order_type')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('app_user')->nullOnDelete();
            $table->foreign('confirmed_by')->references('id')->on('app_user')->nullOnDelete();
            $table->unique(['company_id', 'order_no']);
            $table->index(['company_id', 'customer_id', 'order_date']);
            $table->index(['company_id', 'status']);
            // Optional customer PO uniqueness when provided
            $table->unique(['company_id', 'customer_id', 'customer_po_ref'], 'sales_order_customer_po_unique');
        });

        // Partial unique only when customer_po_ref is not null — replace full unique with partial
        DB::unprepared(<<<'SQL'
            ALTER TABLE sales_order DROP CONSTRAINT IF EXISTS sales_order_customer_po_unique;
            CREATE UNIQUE INDEX sales_order_customer_po_unique
                ON sales_order (company_id, customer_id, customer_po_ref)
                WHERE customer_po_ref IS NOT NULL AND customer_po_ref <> '';
        SQL);

        Schema::create('sales_order_line', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('sales_order_id');
            $table->unsignedInteger('line_no');
            $table->uuid('item_id')->nullable();
            $table->uuid('quotation_line_id')->nullable();
            $table->string('description', 512);
            $table->decimal('qty', 18, 3);
            $table->string('unit', 32)->default('pcs');
            $table->bigInteger('unit_price_minor');
            $table->bigInteger('net_minor');
            $table->bigInteger('tax_minor')->default(0);
            $table->bigInteger('gross_minor');
            $table->decimal('qty_delivered', 18, 3)->default(0);
            $table->decimal('qty_invoiced', 18, 3)->default(0);
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->foreign('sales_order_id')->references('id')->on('sales_order')->restrictOnDelete();
            $table->foreign('item_id')->references('id')->on('item')->nullOnDelete();
            $table->foreign('quotation_line_id')->references('id')->on('quotation_line')->nullOnDelete();
            $table->unique(['sales_order_id', 'line_no']);
            $table->index(['company_id', 'sales_order_id']);
            $table->index(['company_id', 'item_id']);
        });

        // ─── Purchase orders ───────────────────────────────────────────────

        Schema::create('purchase_order', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('po_no', 64);
            $table->uuid('supplier_id');
            $table->date('order_date');
            $table->date('expected_date')->nullable();
            $table->char('currency', 3)->default('GHS');
            $table->decimal('fx_rate', 20, 8)->nullable();
            $table->string('status', 32)->default('draft');
            // draft | approved | cancelled | closed
            $table->bigInteger('net_minor')->default(0);
            $table->bigInteger('tax_total_minor')->default(0);
            $table->bigInteger('gross_minor')->default(0);
            $table->text('memo')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('approved_by')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->foreign('supplier_id')->references('id')->on('party')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('app_user')->nullOnDelete();
            $table->foreign('approved_by')->references('id')->on('app_user')->nullOnDelete();
            $table->unique(['company_id', 'po_no']);
            $table->index(['company_id', 'supplier_id', 'order_date']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('purchase_order_line', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('purchase_order_id');
            $table->unsignedInteger('line_no');
            $table->uuid('item_id')->nullable();
            $table->uuid('sales_order_line_id')->nullable(); // direct / import for a customer order line
            $table->string('description', 512);
            $table->decimal('qty', 18, 3);
            $table->string('unit', 32)->default('pcs');
            $table->bigInteger('unit_price_minor');
            $table->bigInteger('net_minor');
            $table->bigInteger('tax_minor')->default(0);
            $table->bigInteger('gross_minor');
            $table->decimal('qty_received', 18, 3)->default(0);
            $table->string('line_category', 32)->default('stocking');
            // stocking | direct | import
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->foreign('purchase_order_id')->references('id')->on('purchase_order')->restrictOnDelete();
            $table->foreign('item_id')->references('id')->on('item')->nullOnDelete();
            $table->foreign('sales_order_line_id')->references('id')->on('sales_order_line')->nullOnDelete();
            $table->unique(['purchase_order_id', 'line_no']);
            $table->index(['company_id', 'purchase_order_id']);
            $table->index(['company_id', 'sales_order_line_id']);
        });

        // ─── Goods receipts (GRN) ──────────────────────────────────────────

        Schema::create('goods_receipt', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('grn_no', 64);
            $table->uuid('purchase_order_id');
            $table->uuid('location_id'); // receiving location
            $table->date('receipt_date');
            $table->string('status', 32)->default('received');
            // received | qc_complete | cancelled
            $table->uuid('journal_id')->nullable(); // GRNI post
            $table->text('memo')->nullable();
            $table->uuid('created_by')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->foreign('purchase_order_id')->references('id')->on('purchase_order')->restrictOnDelete();
            $table->foreign('location_id')->references('id')->on('location')->restrictOnDelete();
            $table->foreign('journal_id')->references('id')->on('journal')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('app_user')->nullOnDelete();
            $table->unique(['company_id', 'grn_no']);
            $table->index(['company_id', 'purchase_order_id', 'receipt_date']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('goods_receipt_line', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('goods_receipt_id');
            $table->uuid('purchase_order_line_id');
            $table->unsignedInteger('line_no');
            $table->uuid('item_id');
            $table->decimal('qty_received', 18, 3);
            $table->decimal('qty_certified', 18, 3)->default(0);
            $table->decimal('qty_quarantine', 18, 3)->default(0);
            $table->decimal('qty_rejected', 18, 3)->default(0);
            $table->string('lot', 64)->nullable();
            $table->bigInteger('unit_cost_minor')->default(0);
            $table->string('qc_status', 32)->default('pending');
            // pending | certified | quarantine | rejected | partial
            $table->uuid('store_location_id')->nullable(); // destination after QC
            $table->unsignedBigInteger('stock_movement_id')->nullable(); // first receipt movement (stock_movement.id)
            $table->text('qc_note')->nullable();
            $table->uuid('qc_by')->nullable();
            $table->timestampTz('qc_at')->nullable();
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->foreign('goods_receipt_id')->references('id')->on('goods_receipt')->restrictOnDelete();
            $table->foreign('purchase_order_line_id')->references('id')->on('purchase_order_line')->restrictOnDelete();
            $table->foreign('item_id')->references('id')->on('item')->restrictOnDelete();
            $table->foreign('store_location_id')->references('id')->on('location')->nullOnDelete();
            $table->foreign('qc_by')->references('id')->on('app_user')->nullOnDelete();
            $table->unique(['goods_receipt_id', 'line_no']);
            $table->index(['company_id', 'goods_receipt_id']);
            $table->index(['company_id', 'qc_status']);
            $table->index(['company_id', 'purchase_order_line_id']);
        });

        // QC quantities cannot exceed received
        DB::unprepared(<<<'SQL'
            ALTER TABLE goods_receipt_line
                ADD CONSTRAINT goods_receipt_line_qc_qty_check
                CHECK (
                    qty_certified >= 0
                    AND qty_quarantine >= 0
                    AND qty_rejected >= 0
                    AND (qty_certified + qty_quarantine + qty_rejected) <= qty_received
                );
        SQL);

        // Link converted quotation → sales order (after both tables exist)
        Schema::table('quotation', function (Blueprint $table) {
            $table->foreign('converted_sales_order_id')
                ->references('id')->on('sales_order')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_receipt_line');
        Schema::dropIfExists('goods_receipt');
        Schema::dropIfExists('purchase_order_line');
        Schema::dropIfExists('purchase_order');
        Schema::dropIfExists('sales_order_line');
        Schema::table('quotation', function (Blueprint $table) {
            $table->dropForeign(['converted_sales_order_id']);
        });
        Schema::dropIfExists('sales_order');
        Schema::dropIfExists('quotation_line');
        Schema::dropIfExists('quotation');
    }
};
