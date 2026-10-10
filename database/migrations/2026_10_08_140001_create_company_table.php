<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Foundation group 1 — company (legal entity, functional currency, period lock).
 *
 * Source: 02-database.md §5.7, PeriodLockChecker (period_lock_date).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 32)->unique();
            $table->string('legal_name', 255);
            $table->string('tin', 64)->nullable();
            $table->char('functional_currency', 3)->default('GHS');
            $table->unsignedTinyInteger('fiscal_year_start_month')->default(1);
            $table->string('timezone', 64)->default('Africa/Accra');
            // Authoritative lock watermark; journals with posting_date <= this are rejected.
            $table->date('period_lock_date')->nullable();
            $table->text('address')->nullable();
            $table->string('phone', 64)->nullable();
            $table->string('email', 255)->nullable();
            $table->text('bank_details')->nullable();
            $table->string('vat_number', 64)->nullable();
            $table->unsignedInteger('row_version')->default(1);
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company');
    }
};
