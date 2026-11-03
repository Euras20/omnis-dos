<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Goods returns (Delivery › Returns).
 *
 * Customer: from a delivered delivery line → quarantine → QC restock or write-off.
 * Supplier: from quarantine → dispatch back to supplier (reduces GRNI).
 * Does not reopen sales demand. Credit note remains a separate receivables command.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goods_return', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('return_no', 32);
            $table->string('kind', 16); // customer | supplier
            $table->string('status', 32); // awaiting_inspection | restocked | written_off | awaiting_dispatch | dispatched
            $table->uuid('sales_order_id')->nullable();
            $table->uuid('sales_order_line_id')->nullable();
            $table->uuid('delivery_note_id')->nullable();
            $table->uuid('delivery_note_line_id')->nullable();
            $table->uuid('party_id')->nullable();
            $table->uuid('item_id');
            $table->uuid('location_id');
            $table->uuid('restock_location_id')->nullable();
            $table->string('lot', 64)->nullable();
            $table->decimal('qty', 18, 3);
            $table->integer('unit_cost_minor')->default(0);
            $table->text('reason');
            $table->string('authorization', 32)->default('approved');
            $table->uuid('credit_note_id')->nullable();
            $table->uuid('journal_id')->nullable();
            $table->date('return_date');
            $table->integer('row_version')->default(1);
            $table->uuid('created_by')->nullable();
            $table->uuid('inspected_by')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->foreign('sales_order_id')->references('id')->on('sales_order')->restrictOnDelete();
            $table->foreign('delivery_note_id')->references('id')->on('delivery_note')->restrictOnDelete();
            $table->foreign('item_id')->references('id')->on('item')->restrictOnDelete();
            $table->foreign('location_id')->references('id')->on('location')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('app_user')->nullOnDelete();

            $table->unique(['company_id', 'return_no']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'kind']);
            $table->index(['company_id', 'delivery_note_line_id']);
        });

        // Grant the new permission to existing admin roles so current admins can use the screen.
        $codes = ['delivery.return', 'delivery.view'];
        $roles = DB::table('role')
            ->whereIn('code', ['admin', 'system_administrator'])
            ->get(['id', 'company_id']);

        foreach ($roles as $role) {
            foreach ($codes as $code) {
                $exists = DB::table('role_permission')
                    ->where('role_id', $role->id)
                    ->where('permission', $code)
                    ->exists();
                if ($exists) {
                    continue;
                }
                DB::table('role_permission')->insert([
                    'role_id' => $role->id,
                    'permission' => $code,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_return');
    }
};
