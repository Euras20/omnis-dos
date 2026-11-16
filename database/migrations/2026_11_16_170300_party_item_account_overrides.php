<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 17.3 — ledger account overrides on party and item.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('party', function (Blueprint $table) {
            $table->uuid('ar_account_id')->nullable()->after('account_owner');
            $table->uuid('income_account_id')->nullable()->after('ar_account_id');
            $table->uuid('ap_account_id')->nullable()->after('income_account_id');
            $table->uuid('purchase_account_id')->nullable()->after('ap_account_id');
        });

        Schema::table('party', function (Blueprint $table) {
            $table->foreign('ar_account_id')->references('id')->on('account')->nullOnDelete();
            $table->foreign('income_account_id')->references('id')->on('account')->nullOnDelete();
            $table->foreign('ap_account_id')->references('id')->on('account')->nullOnDelete();
            $table->foreign('purchase_account_id')->references('id')->on('account')->nullOnDelete();
        });

        Schema::table('item', function (Blueprint $table) {
            $table->uuid('inv_account_id')->nullable()->after('inventory_account_code');
            $table->uuid('income_account_id')->nullable()->after('inv_account_id');
            $table->uuid('cogs_account_id')->nullable()->after('income_account_id');
        });

        Schema::table('item', function (Blueprint $table) {
            $table->foreign('inv_account_id')->references('id')->on('account')->nullOnDelete();
            $table->foreign('income_account_id')->references('id')->on('account')->nullOnDelete();
            $table->foreign('cogs_account_id')->references('id')->on('account')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('party', function (Blueprint $table) {
            $table->dropForeign(['ar_account_id', 'income_account_id', 'ap_account_id', 'purchase_account_id']);
            $table->dropColumn(['ar_account_id', 'income_account_id', 'ap_account_id', 'purchase_account_id']);
        });

        Schema::table('item', function (Blueprint $table) {
            $table->dropForeign(['inv_account_id', 'income_account_id', 'cogs_account_id']);
            $table->dropColumn(['inv_account_id', 'income_account_id', 'cogs_account_id']);
        });
    }
};
