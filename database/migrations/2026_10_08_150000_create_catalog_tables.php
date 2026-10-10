<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration group 3 — Catalog masters.
 *
 * Source: 02-database.md §5.7, 01-domain-modules.md (Catalog), 03-api.md master data.
 * Tables: party, item_category, item, location, order_type,
 *         tax_rule_version, wht_rule_version, exchange_rate,
 *         document_template, number_sequence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('party', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('code', 64);
            $table->string('name', 255);
            // customer | supplier | carrier | broker
            $table->string('party_type', 32);
            $table->string('tin', 64)->nullable();
            $table->string('reg_no', 64)->nullable();
            $table->string('city', 128)->nullable();
            $table->string('region', 128)->nullable();
            $table->string('country', 64)->nullable();
            $table->text('address')->nullable();
            $table->string('gps', 64)->nullable();
            $table->string('contact_name', 255)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('phone', 64)->nullable();
            $table->string('payment_terms', 128)->nullable();
            $table->bigInteger('credit_limit_minor')->nullable();
            $table->char('currency', 3)->default('GHS');
            $table->boolean('vat_registered')->default(false);
            $table->string('wht_rule_code', 64)->nullable();
            $table->string('account_owner', 255)->nullable();
            $table->boolean('is_main_supplier')->default(false);
            $table->jsonb('contacts')->nullable();
            $table->jsonb('bank_details')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('row_version')->default(1);
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'party_type', 'is_active']);
            $table->index(['company_id', 'name']);
        });

        Schema::create('item_category', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('code', 32);
            $table->string('name', 255);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('row_version')->default(1);
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->unique(['company_id', 'code']);
        });

        Schema::create('location', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('code', 32);
            $table->string('name', 255);
            $table->string('warehouse', 128)->nullable();
            // receiving | quarantine | rejected | store | dispatch
            $table->string('loc_type', 32);
            $table->uuid('keeper_id')->nullable();
            $table->boolean('is_assignable')->default(false);
            $table->unsignedInteger('capacity')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('row_version')->default(1);
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->foreign('keeper_id')->references('id')->on('app_user')->nullOnDelete();
            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'is_active']);
        });

        Schema::create('item', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('sku', 64);
            $table->string('name', 255);
            // Trading good | Spare part | Packaging | Service | …
            $table->string('item_type', 64);
            $table->string('category_code', 32)->nullable();
            $table->string('unit', 32)->default('pcs');
            $table->bigInteger('list_price_minor')->default(0);
            $table->bigInteger('standard_cost_minor')->default(0);
            $table->bigInteger('buy_price_minor')->nullable();
            $table->decimal('weight_kg', 18, 3)->nullable();
            $table->jsonb('dimensions')->nullable();
            $table->uuid('default_location_id')->nullable();
            $table->uuid('default_supplier_id')->nullable();
            $table->string('inventory_policy', 32)->default('Buy');
            $table->boolean('lot_controlled')->default(true);
            $table->decimal('reorder_point', 18, 3)->nullable();
            $table->decimal('safety_stock', 18, 3)->nullable();
            $table->string('inventory_account_code', 32)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('row_version')->default(1);
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->foreign('default_location_id')->references('id')->on('location')->nullOnDelete();
            $table->foreign('default_supplier_id')->references('id')->on('party')->nullOnDelete();
            $table->unique(['company_id', 'sku']);
            $table->index(['company_id', 'item_type', 'is_active']);
            $table->index(['company_id', 'category_code']);
        });

        Schema::create('order_type', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('code', 32);
            $table->string('name', 255);
            $table->string('workflow', 64)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('row_version')->default(1);
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->unique(['company_id', 'code']);
        });

        Schema::create('tax_rule_version', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('code', 64);
            $table->string('name', 255);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            // illustrative combined rate in percent (e.g. 15.000)
            $table->decimal('rate_percent', 10, 4);
            $table->jsonb('components')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('row_version')->default(1);
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->unique(['company_id', 'code', 'effective_from']);
            $table->index(['company_id', 'effective_from']);
        });

        Schema::create('wht_rule_version', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('code', 64);
            $table->string('name', 255);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->decimal('rate_percent', 10, 4);
            // goods | services | other
            $table->string('applies_to', 32)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('row_version')->default(1);
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->unique(['company_id', 'code', 'effective_from']);
            $table->index(['company_id', 'effective_from']);
        });

        Schema::create('exchange_rate', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->char('currency', 3);
            $table->date('rate_date');
            // GHS per unit of foreign currency
            $table->decimal('buy_rate', 20, 8);
            $table->decimal('sell_rate', 20, 8);
            $table->decimal('mid_rate', 20, 8)->nullable();
            $table->string('source', 64)->nullable();
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->unique(['company_id', 'currency', 'rate_date']);
            $table->index(['company_id', 'rate_date']);
        });

        Schema::create('document_template', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('doc_type', 64);
            // published | draft
            $table->string('status', 16);
            $table->unsignedInteger('version')->default(1);
            $table->jsonb('settings')->nullable();
            $table->uuid('published_by')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->unsignedInteger('row_version')->default(1);
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->foreign('published_by')->references('id')->on('app_user')->nullOnDelete();
            $table->index(['company_id', 'doc_type', 'status']);
        });

        // Partial uniques: one published and at most one draft per (company, doc_type)
        DB::statement("
            CREATE UNIQUE INDEX document_template_one_published
            ON document_template (company_id, doc_type)
            WHERE status = 'published'
        ");
        DB::statement("
            CREATE UNIQUE INDEX document_template_one_draft
            ON document_template (company_id, doc_type)
            WHERE status = 'draft'
        ");

        Schema::create('number_sequence', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('doc_type', 64);
            $table->unsignedSmallInteger('fiscal_year');
            $table->unsignedBigInteger('next_value')->default(1);
            $table->string('prefix', 32)->nullable();
            $table->unsignedTinyInteger('pad_length')->default(5);
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->unique(['company_id', 'doc_type', 'fiscal_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('number_sequence');
        Schema::dropIfExists('document_template');
        Schema::dropIfExists('exchange_rate');
        Schema::dropIfExists('wht_rule_version');
        Schema::dropIfExists('tax_rule_version');
        Schema::dropIfExists('order_type');
        Schema::dropIfExists('item');
        Schema::dropIfExists('location');
        Schema::dropIfExists('item_category');
        Schema::dropIfExists('party');
    }
};
