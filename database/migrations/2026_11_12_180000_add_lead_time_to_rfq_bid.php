<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rfq_bid', function (Blueprint $table) {
            $table->unsignedInteger('lead_time_days')->nullable()->after('total_minor');
        });
    }

    public function down(): void
    {
        Schema::table('rfq_bid', function (Blueprint $table) {
            $table->dropColumn('lead_time_days');
        });
    }
};
