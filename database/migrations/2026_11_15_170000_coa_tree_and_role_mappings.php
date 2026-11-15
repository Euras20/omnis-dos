<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Chart of accounts tree + role mappings.
 *
 * Adds parent, system_key, node type and source to account.
 * Seeds the 90 non-posting system groups per company.
 * Does not touch journal or journal_line.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account', function (Blueprint $table) {
            $table->uuid('parent_id')->nullable()->after('company_id');
            $table->string('system_key', 160)->nullable()->after('parent_id');
            $table->string('node_type', 16)->default('POSTING')->after('kind');
            $table->string('source', 16)->default('CLIENT')->after('node_type');
            $table->boolean('is_contra')->default(false)->after('normal_balance');
            $table->string('statement', 80)->nullable()->after('is_contra');
            $table->string('report_section', 120)->nullable()->after('statement');
            $table->unsignedTinyInteger('level')->nullable()->after('report_section');
        });

        DB::statement('ALTER TABLE account ALTER COLUMN code DROP NOT NULL');

        Schema::table('account', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'code']);
        });

        DB::statement(
            'CREATE UNIQUE INDEX account_company_code_unique
             ON account (company_id, lower(code))
             WHERE code IS NOT NULL'
        );
        DB::statement(
            'CREATE UNIQUE INDEX account_company_system_key_unique
             ON account (company_id, system_key)
             WHERE system_key IS NOT NULL'
        );
        DB::statement(
            'CREATE INDEX account_company_parent_idx ON account (company_id, parent_id)'
        );

        Schema::table('account', function (Blueprint $table) {
            $table->foreign('parent_id')
                ->references('id')->on('account')
                ->restrictOnDelete();
        });

        Schema::create('account_role_mapping', function (Blueprint $table) {
            $table->uuid('company_id');
            $table->string('role_key', 64);
            $table->uuid('account_id');
            $table->uuid('updated_by')->nullable();
            $table->timestampTz('updated_at')->useCurrent();

            $table->primary(['company_id', 'role_key']);
            $table->foreign('company_id')->references('id')->on('company')->restrictOnDelete();
            $table->foreign('account_id')->references('id')->on('account')->restrictOnDelete();
            $table->index(['company_id', 'account_id']);
        });

        $this->seedExistingCompanies();
    }

    public function down(): void
    {
        Schema::dropIfExists('account_role_mapping');

        Schema::table('account', function (Blueprint $table) {
            $table->dropForeign(['parent_id']);
        });

        DB::statement('DROP INDEX IF EXISTS account_company_parent_idx');
        DB::statement('DROP INDEX IF EXISTS account_company_system_key_unique');
        DB::statement('DROP INDEX IF EXISTS account_company_code_unique');

        Schema::table('account', function (Blueprint $table) {
            $table->dropColumn([
                'parent_id',
                'system_key',
                'node_type',
                'source',
                'is_contra',
                'statement',
                'report_section',
                'level',
            ]);
        });

        DB::statement("UPDATE account SET code = id::text WHERE code IS NULL");
        DB::statement('ALTER TABLE account ALTER COLUMN code SET NOT NULL');

        Schema::table('account', function (Blueprint $table) {
            $table->unique(['company_id', 'code']);
        });
    }

    private function seedExistingCompanies(): void
    {
        $path = database_path('data/coa_system_groups.json');
        if (! is_file($path)) {
            return;
        }

        $payload = json_decode((string) file_get_contents($path), true);
        $groups = is_array($payload['groups'] ?? null) ? $payload['groups'] : [];
        if ($groups === []) {
            return;
        }

        $companyIds = DB::table('company')->pluck('id');
        $now = now();

        foreach ($companyIds as $companyId) {
            $byKey = [];

            foreach ($groups as $group) {
                $key = (string) $group['system_key'];
                if (DB::table('account')
                    ->where('company_id', $companyId)
                    ->where('system_key', $key)
                    ->exists()) {
                    $byKey[$key] = (string) DB::table('account')
                        ->where('company_id', $companyId)
                        ->where('system_key', $key)
                        ->value('id');

                    continue;
                }

                $parentKey = $group['parent_system_key'] ?? null;
                $id = (string) \Illuminate\Support\Str::uuid();
                $byKey[$key] = $id;

                DB::table('account')->insert([
                    'id' => $id,
                    'company_id' => $companyId,
                    'parent_id' => $parentKey !== null ? ($byKey[$parentKey] ?? null) : null,
                    'system_key' => $key,
                    'code' => null,
                    'name' => (string) $group['name'],
                    'category' => strtolower((string) $group['account_class']),
                    'kind' => 'header',
                    'node_type' => 'GROUP',
                    'source' => 'SYSTEM',
                    'currency' => null,
                    'is_active' => true,
                    'fs_line_item' => $group['report_section'] ?? null,
                    'fs_sub_line' => null,
                    'normal_balance' => strtolower((string) $group['default_normal_balance']),
                    'is_contra' => (bool) ($group['default_contra'] ?? false),
                    'statement' => $group['statement'] ?? null,
                    'report_section' => $group['report_section'] ?? null,
                    'level' => (int) $group['level'],
                    'row_version' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }
};
