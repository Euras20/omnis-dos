<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeds the 90 non-posting system groups for one company.
 * Idempotent on (company_id, system_key). Never creates posting accounts or codes.
 */
final class SeedSystemGroups
{
    public function seed(string $companyId): int
    {
        $path = database_path('data/coa_system_groups.json');
        $payload = json_decode((string) file_get_contents($path), true);
        $groups = is_array($payload['groups'] ?? null) ? $payload['groups'] : [];

        $byKey = DB::table('account')
            ->where('company_id', $companyId)
            ->whereNotNull('system_key')
            ->pluck('id', 'system_key')
            ->all();

        $created = 0;
        $now = now();

        foreach ($groups as $group) {
            $key = (string) $group['system_key'];
            if (isset($byKey[$key])) {
                continue;
            }

            $parentKey = $group['parent_system_key'] ?? null;
            $id = (string) Str::uuid();
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
            $created++;
        }

        return $created;
    }
}
