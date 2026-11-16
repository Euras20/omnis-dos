<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 17.2 — bank accounts linked to ledger accounts.
 *
 * Adds type and gl_account_id to company_bank_account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_bank_account', function (Blueprint $table) {
            $table->string('type', 32)->default('Bank')->after('company_id');
            $table->uuid('gl_account_id')->nullable()->after('type');
        });

        Schema::table('company_bank_account', function (Blueprint $table) {
            $table->foreign('gl_account_id')
                ->references('id')->on('account')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('company_bank_account', function (Blueprint $table) {
            $table->dropForeign(['gl_account_id']);
            $table->dropColumn(['type', 'gl_account_id']);
        });
    }
};
