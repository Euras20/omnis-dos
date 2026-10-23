<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('party', function (Blueprint $table) {
            $table->string('delivery_address', 512)->nullable()->after('address');
            $table->string('delivery_terms', 16)->nullable()->after('delivery_address');
        });

        Schema::table('quotation', function (Blueprint $table) {
            $table->string('delivery_location', 255)->nullable()->after('memo');
            $table->string('delivery_type', 16)->nullable()->after('delivery_location');
            $table->string('supplier_quote_ref', 255)->nullable()->after('delivery_type');
        });

        Schema::table('quotation_line', function (Blueprint $table) {
            $table->boolean('to_order')->default(false)->after('item_id');
        });

        Schema::create('company_bank_account', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('bank_name', 128);
            $table->string('account_name', 255);
            $table->string('account_number', 64);
            $table->string('branch', 128)->nullable();
            $table->string('swift', 16)->nullable();
            $table->char('currency', 3)->default('GHS');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->index(['company_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_bank_account');
        Schema::table('quotation_line', function (Blueprint $table) {
            $table->dropColumn('to_order');
        });
        Schema::table('quotation', function (Blueprint $table) {
            $table->dropColumn(['delivery_location', 'delivery_type', 'supplier_quote_ref']);
        });
        Schema::table('party', function (Blueprint $table) {
            $table->dropColumn(['delivery_address', 'delivery_terms']);
        });
    }
};
