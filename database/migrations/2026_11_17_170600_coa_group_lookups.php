<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seed the client account-group lookup lists (main groups + subgroups)
 * as system GROUP nodes under the existing class roots.
 */
return new class extends Migration
{
    public function up(): void
    {
        $path = database_path('data/coa_group_lookups.json');
        if (! is_file($path)) {
            return;
        }

        $payload = json_decode((string) file_get_contents($path), true);
        $main = is_array($payload['main_groups'] ?? null) ? $payload['main_groups'] : [];
        $sub = is_array($payload['subgroups'] ?? null) ? $payload['subgroups'] : [];

        $companyIds = DB::table('company')->pluck('id');
        $now = now();

        foreach ($companyIds as $companyId) {
            $byKey = [];

            foreach (array_merge($main, $sub) as $group) {
                $key = (string) $group['system_key'];
                $existing = DB::table('account')
                    ->where('company_id', $companyId)
                    ->where('system_key', $key)
                    ->value('id');

                if ($existing !== null) {
                    $byKey[$key] = (string) $existing;
                    continue;
                }

                $parentKey = $group['parent'];
                $parentId = $byKey[$parentKey] ?? DB::table('account')
                    ->where('company_id', $companyId)
                    ->where('system_key', $parentKey)
                    ->value('id');

                if ($parentId === null) {
                    continue;
                }

                $parent = DB::table('account')->where('id', $parentId)->first();
                $id = (string) Str::uuid();
                $byKey[$key] = $id;

                $isMain = str_starts_with($key, 'MG.');
                $category = $group['class'] ?? ($parent->category ?? 'expense');

                DB::table('account')->insert([
                    'id' => $id,
                    'company_id' => $companyId,
                    'parent_id' => $parentId,
                    'system_key' => $key,
                    'code' => null,
                    'name' => (string) $group['name'],
                    'category' => strtolower((string) $category),
                    'kind' => 'header',
                    'node_type' => 'GROUP',
                    'source' => 'SYSTEM',
                    'currency' => null,
                    'is_active' => true,
                    'fs_line_item' => $group['name'],
                    'fs_sub_line' => null,
                    'normal_balance' => in_array($category, ['liability', 'equity', 'income'], true) ? 'credit' : 'debit',
                    'is_contra' => false,
                    'statement' => $parent->statement ?? null,
                    'report_section' => $group['name'],
                    'level' => $isMain ? 2 : 3,
                    'row_version' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('account')
            ->where('system_key', 'like', 'MG.%')
            ->orWhere('system_key', 'like', 'SG.%')
            ->delete();
    }
};
