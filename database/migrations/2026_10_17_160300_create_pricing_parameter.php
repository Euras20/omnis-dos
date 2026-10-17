<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 16.3 — Company pricing parameters used by the pricing calculator.
 *
 * One row per company. Stores the same structure the frontend uses for
 * duty / insurance / clearance / markup / contingency (each pct or amt),
 * DHL volumetric divisor and bands, and extra costs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pricing_parameter', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            // Full parameter set matching frontend S.settings.pricing
            $table->jsonb('parameters');
            $table->unsignedInteger('row_version')->default(1);
            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->unique('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_parameter');
    }
};
