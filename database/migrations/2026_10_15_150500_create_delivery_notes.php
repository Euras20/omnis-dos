<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 15.5 Delivery workflow (demo flow).
 *
 * delivery_note tracks the multi-stage board:
 *   planned → picking → packed → dispatched → delivered | failed
 *
 * Stock issue + COGS journal happens only on confirm (delivered).
 * Lines reference the assigned stock that will be issued.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_note', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('delivery_no', 32);
            $table->uuid('sales_order_id');
            $table->string('status', 32)->default('planned');
            // planned | picking | packed | dispatched | delivered | failed | cancelled
            $table->date('planned_date');
            $table->date('revised_date')->nullable();
            $table->string('route', 255)->nullable();
            $table->string('packages', 128)->nullable();
            $table->decimal('weight_kg', 12, 2)->nullable();
            $table->decimal('volume_m3', 12, 3)->nullable();
            $table->string('vehicle', 64)->nullable();
            $table->string('carrier', 128)->nullable();
            $table->string('driver', 128)->nullable();
            $table->string('picked_by', 128)->nullable();
            $table->string('packed_by', 128)->nullable();
            $table->timestampTz('dispatch_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->unsignedSmallInteger('attempt')->default(1);
            $table->string('receiver', 255)->nullable();
            $table->string('pod_reference', 64)->nullable();
            $table->text('note')->nullable();
            $table->text('fail_reason')->nullable();
            $table->integer('row_version')->default(1);
            $table->uuid('created_by')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->foreign('sales_order_id')->references('id')->on('sales_order')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('app_user')->nullOnDelete();

            $table->unique(['company_id', 'delivery_no']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'sales_order_id']);
            $table->index(['company_id', 'planned_date']);
        });

        Schema::create('delivery_note_line', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('delivery_note_id');
            $table->uuid('sales_order_line_id')->nullable();
            $table->uuid('stock_assignment_id');
            $table->uuid('item_id');
            $table->uuid('location_id');
            $table->string('lot', 64)->nullable();
            $table->decimal('qty', 18, 3);
            $table->decimal('accepted_qty', 18, 3)->nullable();
            $table->decimal('refused_qty', 18, 3)->nullable();
            $table->integer('line_no')->default(1);
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->foreign('delivery_note_id')->references('id')->on('delivery_note')->cascadeOnDelete();
            $table->foreign('stock_assignment_id')->references('id')->on('stock_assignment')->restrictOnDelete();
            $table->foreign('item_id')->references('id')->on('item')->restrictOnDelete();
            $table->foreign('location_id')->references('id')->on('location')->restrictOnDelete();

            $table->index(['company_id', 'delivery_note_id']);
            $table->index(['company_id', 'stock_assignment_id']);
            $table->index(['company_id', 'sales_order_line_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_note_line');
        Schema::dropIfExists('delivery_note');
    }
};
