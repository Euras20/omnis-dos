<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 17.4 — opening balance tracking columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('party', function (Blueprint $table) {
            $table->uuid('opening_balance_doc_id')->nullable()->after('purchase_account_id');
            $table->date('opening_balance_date')->nullable()->after('opening_balance_doc_id');
            $table->bigInteger('opening_balance_minor')->nullable()->after('opening_balance_date');
        });

        Schema::table('item', function (Blueprint $table) {
            $table->uuid('opening_balance_doc_id')->nullable()->after('cogs_account_id');
            $table->date('opening_balance_date')->nullable()->after('opening_balance_doc_id');
            $table->decimal('opening_balance_qty', 18, 3)->nullable()->after('opening_balance_date');
            $table->bigInteger('opening_balance_minor')->nullable()->after('opening_balance_qty');
        });

        Schema::table('company_bank_account', function (Blueprint $table) {
            $table->uuid('opening_balance_doc_id')->nullable()->after('gl_account_id');
            $table->date('opening_balance_date')->nullable()->after('opening_balance_doc_id');
            $table->bigInteger('opening_balance_minor')->nullable()->after('opening_balance_date');
        });
    }

    public function down(): void
    {
        Schema::table('party', function (Blueprint $table) {
            $table->dropColumn(['opening_balance_doc_id', 'opening_balance_date', 'opening_balance_minor']);
        });

        Schema::table('item', function (Blueprint $table) {
            $table->dropColumn(['opening_balance_doc_id', 'opening_balance_date', 'opening_balance_qty', 'opening_balance_minor']);
        });

        Schema::table('company_bank_account', function (Blueprint $table) {
            $table->dropColumn(['opening_balance_doc_id', 'opening_balance_date', 'opening_balance_minor']);
        });
    }
};
