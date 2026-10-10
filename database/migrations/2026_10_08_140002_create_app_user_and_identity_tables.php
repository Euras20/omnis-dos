<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Foundation group 1 — app_user + RBAC tables.
 *
 * Tables: app_user, role, role_permission, user_role,
 *         user_permission_override, user_location_scope.
 *
 * Permission codes are free-form text (module.area.action) matching the frontend.
 * No separate permission catalogue table — codes are the contract.
 *
 * Source: 02-database.md §5.7, 06-authn-authz.md, 13-users-roles-permissions.md,
 *         PermissionChecker (Shared).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_user', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('email', 255);
            $table->string('display_name', 255);
            $table->string('external_subject', 255)->nullable();
            $table->string('user_type', 32)->default('staff'); // staff | portal
            $table->uuid('party_id')->nullable(); // FK added when Catalog party exists
            $table->uuid('employee_id')->nullable(); // FK added when Payroll employee exists
            $table->boolean('is_active')->default(true);
            $table->string('phone', 64)->nullable();
            $table->string('job_title', 128)->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampTz('deactivated_at')->nullable();
            $table->text('deactivation_reason')->nullable();
            $table->unsignedInteger('row_version')->default(1);
            $table->timestampsTz();

            $table->foreign('company_id')
                ->references('id')->on('company')
                ->onDelete('restrict');

            $table->unique(['company_id', 'email']);
            $table->index(['company_id', 'is_active']);
            $table->index(['company_id', 'external_subject']);
        });

        // Partial unique: one external_subject per company when set
        DB::statement(
            'CREATE UNIQUE INDEX app_user_company_external_subject_unique
             ON app_user (company_id, external_subject)
             WHERE external_subject IS NOT NULL'
        );

        Schema::create('role', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('code', 64);
            $table->string('name', 128);
            $table->text('description')->nullable();
            $table->bigInteger('approval_limit_minor')->nullable(); // money minor units
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('row_version')->default(1);
            $table->timestampsTz();

            $table->foreign('company_id')
                ->references('id')->on('company')
                ->onDelete('restrict');

            $table->unique(['company_id', 'code']);
        });

        Schema::create('role_permission', function (Blueprint $table) {
            $table->uuid('role_id');
            $table->string('permission', 128);
            $table->primary(['role_id', 'permission']);

            $table->foreign('role_id')
                ->references('id')->on('role')
                ->onDelete('cascade');
        });

        Schema::create('user_role', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('user_id');
            $table->uuid('role_id');
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->timestampsTz();

            $table->foreign('company_id')
                ->references('id')->on('company')
                ->onDelete('restrict');
            $table->foreign('user_id')
                ->references('id')->on('app_user')
                ->onDelete('cascade');
            $table->foreign('role_id')
                ->references('id')->on('role')
                ->onDelete('restrict');

            $table->unique(['user_id', 'role_id']);
            $table->index(['company_id', 'user_id']);
        });

        Schema::create('user_permission_override', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('user_id');
            $table->string('permission', 128);
            $table->string('effect', 16); // grant | deny
            $table->text('reason')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            $table->foreign('company_id')
                ->references('id')->on('company')
                ->onDelete('restrict');
            $table->foreign('user_id')
                ->references('id')->on('app_user')
                ->onDelete('cascade');

                       $table->unique(['company_id', 'user_id', 'permission']);
        });

        // CHECK constraint (Blueprint::check is not available on all Laravel builds)
        DB::statement(
            "ALTER TABLE user_permission_override
             ADD CONSTRAINT user_permission_override_effect_check
             CHECK (effect IN ('grant', 'deny'))"
        );

        // location_id has no FK yet — Catalog location lands in group 3
        Schema::create('user_location_scope', function (Blueprint $table) {
            $table->uuid('company_id');
            $table->uuid('user_id');
            $table->uuid('location_id');
            $table->primary(['user_id', 'location_id']);

            $table->foreign('company_id')
                ->references('id')->on('company')
                ->onDelete('restrict');
            $table->foreign('user_id')
                ->references('id')->on('app_user')
                ->onDelete('cascade');

            $table->index(['company_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_location_scope');
        Schema::dropIfExists('user_permission_override');
        Schema::dropIfExists('user_role');
        Schema::dropIfExists('role_permission');
        Schema::dropIfExists('role');
        Schema::dropIfExists('app_user');
    }
};
