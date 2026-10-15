<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 15.6 Logistics (demo flow, jobs excluded).
 *
 * - One shipment can carry consignments from many purchase orders (bulk).
 * - Multi-leg timeline + cargo events per consignment.
 * - Customs declarations with document checklist and release.
 * - Entered landed costs allocated by quantity / weight / volume.
 *
 * Jobs (customer-owned cargo service) are intentionally omitted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('shipment_no', 32);
            $table->string('kind', 32)->default('Import'); // Import | Domestic | Direct
            $table->string('transport', 16)->default('Sea'); // Sea | Air | Road
            $table->string('mode', 64)->nullable(); // e.g. "Sea + road"
            $table->string('booking', 64)->nullable();
            $table->string('incoterm', 64)->nullable();
            $table->uuid('shipper_id')->nullable();
            $table->string('consignee', 255)->nullable(); // "Company" or party name
            $table->uuid('carrier_id')->nullable();
            $table->uuid('forwarder_id')->nullable();
            $table->string('insurance', 255)->nullable();
            $table->string('status', 32)->default('Booked');
            // Booked | In transit | Customs hold | Cleared | Partially received | Received | Cancelled
            $table->string('owner', 128)->nullable();
            $table->text('note')->nullable();
            $table->integer('row_version')->default(1);
            $table->uuid('created_by')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->foreign('shipper_id')->references('id')->on('party')->nullOnDelete();
            $table->foreign('carrier_id')->references('id')->on('party')->nullOnDelete();
            $table->foreign('forwarder_id')->references('id')->on('party')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('app_user')->nullOnDelete();

            $table->unique(['company_id', 'shipment_no']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'booking']);
        });

        // Bulk: one shipment can cover many purchase orders
        Schema::create('shipment_purchase_order', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('shipment_id');
            $table->uuid('purchase_order_id');
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->foreign('shipment_id')->references('id')->on('shipment')->cascadeOnDelete();
            $table->foreign('purchase_order_id')->references('id')->on('purchase_order')->restrictOnDelete();

            $table->unique(['shipment_id', 'purchase_order_id']);
            $table->index(['company_id', 'purchase_order_id']);
        });

        Schema::create('consignment', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('shipment_id');
            $table->string('consignment_no', 32);
            $table->uuid('purchase_order_line_id')->nullable(); // links to a specific PO line (bulk support)
            $table->uuid('item_id')->nullable();
            $table->decimal('qty', 18, 3);
            $table->string('container', 64)->nullable();
            $table->string('seal', 64)->nullable();
            $table->string('bl', 64)->nullable();
            $table->unsignedInteger('packages')->default(0);
            $table->decimal('weight_kg', 12, 2)->nullable();
            $table->decimal('volume_m3', 12, 3)->nullable();
            $table->string('status', 32)->default('Booked');
            // Booked | In transit | Customs hold | Arrived | Received | Rolled
            $table->date('eta')->nullable();
            $table->uuid('goods_receipt_id')->nullable();
            $table->integer('row_version')->default(1);
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->foreign('shipment_id')->references('id')->on('shipment')->cascadeOnDelete();
            $table->foreign('purchase_order_line_id')->references('id')->on('purchase_order_line')->nullOnDelete();
            $table->foreign('item_id')->references('id')->on('item')->nullOnDelete();

            $table->unique(['company_id', 'consignment_no']);
            $table->index(['company_id', 'shipment_id']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'purchase_order_line_id']);
        });

        Schema::create('shipment_leg', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('consignment_id');
            $table->unsignedSmallInteger('seq')->default(1);
            $table->string('from_location', 128);
            $table->string('to_location', 128);
            $table->uuid('carrier_id')->nullable();
            $table->string('vessel', 128)->nullable();
            $table->date('planned_dep')->nullable();
            $table->date('planned_arr')->nullable();
            $table->date('actual_dep')->nullable();
            $table->date('actual_arr')->nullable();
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->foreign('consignment_id')->references('id')->on('consignment')->cascadeOnDelete();
            $table->foreign('carrier_id')->references('id')->on('party')->nullOnDelete();

            $table->index(['company_id', 'consignment_id']);
        });

        Schema::create('shipment_event', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('shipment_id');
            $table->uuid('consignment_id')->nullable();
            $table->timestampTz('event_at');
            $table->string('event_type', 64);
            $table->string('location', 128)->nullable();
            $table->decimal('qty_affected', 18, 3)->nullable();
            $table->string('source', 255)->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->foreign('shipment_id')->references('id')->on('shipment')->cascadeOnDelete();
            $table->foreign('consignment_id')->references('id')->on('consignment')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('app_user')->nullOnDelete();

            $table->index(['company_id', 'shipment_id', 'event_at']);
        });

        Schema::create('customs_declaration', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('declaration_no', 32);
            $table->uuid('shipment_id');
            $table->uuid('consignment_id')->nullable();
            $table->string('jurisdiction', 64)->nullable();
            $table->uuid('broker_id')->nullable();
            $table->string('hs_code', 32)->nullable();
            $table->string('valuation', 128)->nullable();
            $table->bigInteger('duties_minor')->default(0);
            $table->bigInteger('recoverable_tax_minor')->default(0);
            $table->bigInteger('nonrecoverable_minor')->default(0);
            $table->string('inspection', 64)->nullable();
            $table->string('status', 32)->default('Pre-lodged');
            // Pre-lodged | Pending | Hold | Cleared
            $table->string('payment', 128)->nullable();
            $table->date('release_date')->nullable();
            $table->date('free_time_end')->nullable();
            $table->bigInteger('demurrage_minor')->default(0);
            $table->jsonb('docs')->nullable(); // checklist { "Commercial invoice": true, ... }
            $table->integer('row_version')->default(1);
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->foreign('shipment_id')->references('id')->on('shipment')->cascadeOnDelete();
            $table->foreign('consignment_id')->references('id')->on('consignment')->nullOnDelete();
            $table->foreign('broker_id')->references('id')->on('party')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('app_user')->nullOnDelete();

            $table->unique(['company_id', 'declaration_no']);
            $table->index(['company_id', 'shipment_id']);
            $table->index(['company_id', 'status']);
        });

        // Entered landed costs (freight, duty, insurance, clearance, extras, supplier purchase)
        Schema::create('landed_cost', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('shipment_id');
            $table->uuid('consignment_id')->nullable(); // null = whole shipment (to be allocated)
            $table->string('category', 64); // Freight | Insurance | Duty | Clearance | Supplier purchase | Other
            $table->string('description', 255)->nullable();
            $table->bigInteger('amount_minor');
            $table->char('currency', 3)->default('GHS');
            $table->boolean('recoverable')->default(false);
            $table->string('status', 32)->default('Committed'); // Committed | Actual
            $table->string('source', 128)->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->foreign('shipment_id')->references('id')->on('shipment')->cascadeOnDelete();
            $table->foreign('consignment_id')->references('id')->on('consignment')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('app_user')->nullOnDelete();

            $table->index(['company_id', 'shipment_id']);
            $table->index(['company_id', 'consignment_id']);
        });

        Schema::create('eta_revision', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('shipment_id')->nullable();
            $table->uuid('sales_order_id')->nullable();
            $table->date('original_promise')->nullable();
            $table->date('previous')->nullable();
            $table->date('revised');
            $table->string('source', 64)->nullable();
            $table->string('confidence', 32)->nullable();
            $table->text('reason')->nullable();
            $table->string('revised_by', 128)->nullable();
            $table->timestampTz('revised_at');
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->foreign('shipment_id')->references('id')->on('shipment')->nullOnDelete();
            $table->foreign('sales_order_id')->references('id')->on('sales_order')->nullOnDelete();

            $table->index(['company_id', 'shipment_id']);
            $table->index(['company_id', 'sales_order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eta_revision');
        Schema::dropIfExists('landed_cost');
        Schema::dropIfExists('customs_declaration');
        Schema::dropIfExists('shipment_event');
        Schema::dropIfExists('shipment_leg');
        Schema::dropIfExists('consignment');
        Schema::dropIfExists('shipment_purchase_order');
        Schema::dropIfExists('shipment');
    }
};
