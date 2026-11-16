<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 17.1 — Chart of accounts enhancements.
 *
 * Adds detail type, description, and lock fields to account.
 * Does not change journal or posting rules.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account', function (Blueprint $table) {
            $table->string('detail_type', 80)->nullable()->after('category');
            $table->string('description', 512)->nullable()->after('name');
            $table->boolean('is_locked')->default(false)->after('is_active');
            $table->uuid('locked_by')->nullable()->after('is_locked');
            $table->timestampTz('locked_at')->nullable()->after('locked_by');
            $table->string('lock_reason', 512)->nullable()->after('locked_at');
        });

        Schema::table('account', function (Blueprint $table) {
            $table->foreign('locked_by')
                ->references('id')->on('app_user')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('account', function (Blueprint $table) {
            $table->dropForeign(['locked_by']);
            $table->dropColumn([
                'detail_type',
                'description',
                'is_locked',
                'locked_by',
                'locked_at',
                'lock_reason',
            ]);
        });
    }
};
