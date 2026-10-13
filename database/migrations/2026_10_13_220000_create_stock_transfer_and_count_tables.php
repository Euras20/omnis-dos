<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stock transfers (location-to-location) and cycle-count sessions.
 * Matches frontend: inventory/transfers, inventory/counts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transfer', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('transfer_no', 64);
            $table->uuid('item_id');
            $table->uuid('from_location_id');
            $table->uuid('to_location_id');
            $table->string('lot', 64)->nullable();
            $table->decimal('qty', 18, 3);
            $table->date('transfer_date');
            $table->string('status', 32)->default('received'); // received (MVP: complete in one step)
            $table->bigInteger('out_movement_id')->nullable();
            $table->bigInteger('in_movement_id')->nullable();
            $table->text('memo')->nullable();
            $table->uuid('created_by')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->foreign('item_id')->references('id')->on('item')->restrictOnDelete();
            $table->foreign('from_location_id')->references('id')->on('location')->restrictOnDelete();
            $table->foreign('to_location_id')->references('id')->on('location')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('app_user')->nullOnDelete();
            $table->unique(['company_id', 'transfer_no']);
            $table->index(['company_id', 'transfer_date']);
            $table->index(['company_id', 'item_id']);
        });

        Schema::create('stock_count', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('count_no', 64);
            $table->uuid('location_id');
            $table->date('count_date');
            $table->string('status', 32)->default('draft');
            // draft | awaiting_supervisor | posted | rejected
            $table->uuid('counted_by')->nullable();
            $table->timestampTz('counted_at')->nullable();
            $table->uuid('approved_by')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->text('memo')->nullable();
            $table->uuid('created_by')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->foreign('location_id')->references('id')->on('location')->restrictOnDelete();
            $table->foreign('counted_by')->references('id')->on('app_user')->nullOnDelete();
            $table->foreign('approved_by')->references('id')->on('app_user')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('app_user')->nullOnDelete();
            $table->unique(['company_id', 'count_no']);
            $table->index(['company_id', 'status', 'count_date']);
        });

        Schema::create('stock_count_line', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('stock_count_id');
            $table->unsignedInteger('line_no');
            $table->uuid('item_id');
            $table->string('lot', 64)->nullable();
            $table->decimal('book_qty', 18, 3);
            $table->decimal('counted_qty', 18, 3);
            $table->text('reason')->nullable();
            $table->bigInteger('adjustment_movement_id')->nullable();
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->foreign('stock_count_id')->references('id')->on('stock_count')->cascadeOnDelete();
            $table->foreign('item_id')->references('id')->on('item')->restrictOnDelete();
            $table->unique(['stock_count_id', 'line_no']);
            $table->index(['company_id', 'stock_count_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_count_line');
        Schema::dropIfExists('stock_count');
        Schema::dropIfExists('stock_transfer');
    }
};
