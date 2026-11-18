<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wholesale / retail: two prices on item, channel on party and sales documents.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('item', function (Blueprint $table) {
            $table->bigInteger('wholesale_price_minor')->nullable()->after('list_price_minor');
            $table->bigInteger('retail_price_minor')->nullable()->after('wholesale_price_minor');
        });

        Schema::table('party', function (Blueprint $table) {
            $table->string('sales_channel', 16)->nullable()->after('party_type');
        });

        Schema::table('quotation', function (Blueprint $table) {
            $table->string('sales_channel', 16)->nullable()->after('customer_id');
        });

        Schema::table('sales_order', function (Blueprint $table) {
            $table->string('sales_channel', 16)->nullable()->after('customer_id');
        });

        Schema::table('invoice', function (Blueprint $table) {
            $table->string('sales_channel', 16)->nullable()->after('customer_id');
        });
    }

    public function down(): void
    {
        Schema::table('item', function (Blueprint $table) {
            $table->dropColumn(['wholesale_price_minor', 'retail_price_minor']);
        });

        Schema::table('party', function (Blueprint $table) {
            $table->dropColumn('sales_channel');
        });

        Schema::table('quotation', function (Blueprint $table) {
            $table->dropColumn('sales_channel');
        });

        Schema::table('sales_order', function (Blueprint $table) {
            $table->dropColumn('sales_channel');
        });

        Schema::table('invoice', function (Blueprint $table) {
            $table->dropColumn('sales_channel');
        });
    }
};
