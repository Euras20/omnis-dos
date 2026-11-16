<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 17.5 — line and header discounts + approval workflow.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotation_line', function (Blueprint $table) {
            $table->decimal('discount_percent', 5, 2)->default(0)->after('unit_price_minor');
        });

        Schema::table('sales_order', function (Blueprint $table) {
            $table->decimal('discount_percent', 5, 2)->default(0)->after('currency');
            $table->string('discount_status', 16)->default('none')->after('discount_percent');
            $table->uuid('discount_approved_by')->nullable()->after('discount_status');
            $table->timestampTz('discount_approved_at')->nullable()->after('discount_approved_by');
            $table->string('discount_reason', 512)->nullable()->after('discount_approved_at');
        });

        Schema::table('sales_order_line', function (Blueprint $table) {
            $table->decimal('discount_percent', 5, 2)->default(0)->after('unit_price_minor');
        });

        Schema::table('quotation', function (Blueprint $table) {
            $table->string('discount_status', 16)->default('none')->after('discount_percent');
            $table->uuid('discount_approved_by')->nullable()->after('discount_status');
            $table->timestampTz('discount_approved_at')->nullable()->after('discount_approved_by');
            $table->string('discount_reason', 512)->nullable()->after('discount_approved_at');
        });
    }

    public function down(): void
    {
        Schema::table('quotation_line', function (Blueprint $table) {
            $table->dropColumn('discount_percent');
        });

        Schema::table('sales_order', function (Blueprint $table) {
            $table->dropColumn([
                'discount_percent',
                'discount_status',
                'discount_approved_by',
                'discount_approved_at',
                'discount_reason',
            ]);
        });

        Schema::table('sales_order_line', function (Blueprint $table) {
            $table->dropColumn('discount_percent');
        });

        Schema::table('quotation', function (Blueprint $table) {
            $table->dropColumn([
                'discount_status',
                'discount_approved_by',
                'discount_approved_at',
                'discount_reason',
            ]);
        });
    }
};
