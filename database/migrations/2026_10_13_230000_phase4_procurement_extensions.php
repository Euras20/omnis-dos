<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 procurement extensions:
 * - bill_line (+ optional purchase_order_line_id)
 * - bill status allows mismatch (resolve → posted)
 * - requisition + lines
 * - rfq + lines + bids
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bill_line', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('bill_id');
            $table->unsignedInteger('line_no');
            $table->uuid('purchase_order_line_id')->nullable();
            $table->uuid('item_id')->nullable();
            $table->string('description', 512);
            $table->decimal('qty', 18, 3)->default(1);
            $table->string('unit', 32)->default('pcs');
            $table->bigInteger('unit_price_minor')->default(0);
            $table->bigInteger('net_minor')->default(0);
            $table->bigInteger('tax_minor')->default(0);
            $table->bigInteger('gross_minor')->default(0);
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->foreign('bill_id')->references('id')->on('bill')->restrictOnDelete();
            $table->foreign('purchase_order_line_id')->references('id')->on('purchase_order_line')->nullOnDelete();
            $table->foreign('item_id')->references('id')->on('item')->nullOnDelete();
            $table->unique(['bill_id', 'line_no']);
            $table->index(['company_id', 'bill_id']);
            $table->index(['company_id', 'purchase_order_line_id']);
        });

        // Expand bill status comment surface (app-enforced): posted | void | mismatch
        DB::statement("COMMENT ON COLUMN bill.status IS 'posted | void | mismatch'");

        Schema::create('requisition', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('req_no', 64);
            $table->uuid('requested_by')->nullable();
            $table->date('request_date');
            $table->string('status', 32)->default('draft');
            // draft | pending | approved | rejected | converted
            $table->uuid('approved_by')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->uuid('rejected_by')->nullable();
            $table->timestampTz('rejected_at')->nullable();
            $table->text('reject_reason')->nullable();
            $table->uuid('converted_purchase_order_id')->nullable();
            $table->uuid('preferred_supplier_id')->nullable();
            $table->text('memo')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->foreign('requested_by')->references('id')->on('app_user')->nullOnDelete();
            $table->foreign('approved_by')->references('id')->on('app_user')->nullOnDelete();
            $table->foreign('rejected_by')->references('id')->on('app_user')->nullOnDelete();
            $table->foreign('converted_purchase_order_id')->references('id')->on('purchase_order')->nullOnDelete();
            $table->foreign('preferred_supplier_id')->references('id')->on('party')->nullOnDelete();
            $table->unique(['company_id', 'req_no']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('requisition_line', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('requisition_id');
            $table->unsignedInteger('line_no');
            $table->uuid('item_id')->nullable();
            $table->string('description', 512);
            $table->decimal('qty', 18, 3);
            $table->string('unit', 32)->default('pcs');
            $table->bigInteger('estimated_unit_price_minor')->default(0);
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->foreign('requisition_id')->references('id')->on('requisition')->restrictOnDelete();
            $table->foreign('item_id')->references('id')->on('item')->nullOnDelete();
            $table->unique(['requisition_id', 'line_no']);
            $table->index(['company_id', 'requisition_id']);
        });

        Schema::create('rfq', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('rfq_no', 64);
            $table->date('issue_date');
            $table->date('due_date')->nullable();
            $table->string('status', 32)->default('open');
            // open | closed | awarded | cancelled
            $table->uuid('awarded_bid_id')->nullable();
            $table->uuid('awarded_purchase_order_id')->nullable();
            $table->uuid('created_by')->nullable();
            $table->text('memo')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('app_user')->nullOnDelete();
            $table->foreign('awarded_purchase_order_id')->references('id')->on('purchase_order')->nullOnDelete();
            $table->unique(['company_id', 'rfq_no']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('rfq_line', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('rfq_id');
            $table->unsignedInteger('line_no');
            $table->uuid('item_id')->nullable();
            $table->string('description', 512);
            $table->decimal('qty', 18, 3);
            $table->string('unit', 32)->default('pcs');
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->foreign('rfq_id')->references('id')->on('rfq')->restrictOnDelete();
            $table->foreign('item_id')->references('id')->on('item')->nullOnDelete();
            $table->unique(['rfq_id', 'line_no']);
            $table->index(['company_id', 'rfq_id']);
        });

        Schema::create('rfq_bid', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('rfq_id');
            $table->uuid('supplier_id');
            $table->date('bid_date');
            $table->char('currency', 3)->default('GHS');
            $table->bigInteger('total_minor')->default(0);
            $table->string('status', 32)->default('submitted');
            // submitted | withdrawn | awarded | rejected
            $table->jsonb('line_prices')->nullable(); // [{rfqLineId, unitPriceMinor}]
            $table->text('memo')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->foreign('rfq_id')->references('id')->on('rfq')->restrictOnDelete();
            $table->foreign('supplier_id')->references('id')->on('party')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('app_user')->nullOnDelete();
            $table->index(['company_id', 'rfq_id']);
            $table->unique(['rfq_id', 'supplier_id']);
        });

        // Deferred FK: awarded_bid_id → rfq_bid (created after rfq table)
        Schema::table('rfq', function (Blueprint $table) {
            $table->foreign('awarded_bid_id')->references('id')->on('rfq_bid')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('rfq', function (Blueprint $table) {
            $table->dropForeign(['awarded_bid_id']);
        });
        Schema::dropIfExists('rfq_bid');
        Schema::dropIfExists('rfq_line');
        Schema::dropIfExists('rfq');
        Schema::dropIfExists('requisition_line');
        Schema::dropIfExists('requisition');
        Schema::dropIfExists('bill_line');
    }
};
