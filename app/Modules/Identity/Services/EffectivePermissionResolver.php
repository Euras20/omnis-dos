<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Models\AppUser;
use App\Modules\Identity\Domain\PermissionCatalogue;
use Illuminate\Support\Facades\DB;

/**
 * Resolves effective permissions for a user with source attribution.
 *
 * Resolution mirrors PermissionChecker (deny wins, then grant, then role union)
 * and adds "why" for the admin Effective Access / Access Review screens.
 */
final class EffectivePermissionResolver
{
    /**
     * @return list<array{permission: string, allowed: bool, source: string, detail: ?string}>
     */
    public function forUser(AppUser $user): array
    {
        $companyId = (string) $user->company_id;
        $userId = (string) $user->id;

        $roleRows = DB::table('user_role as ur')
            ->join('role as r', 'r.id', '=', 'ur.role_id')
            ->join('role_permission as rp', 'rp.role_id', '=', 'ur.role_id')
            ->where('ur.company_id', $companyId)
            ->where('ur.user_id', $userId)
            ->where(function ($q) {
                $q->whereNull('ur.valid_to')
                    ->orWhere('ur.valid_to', '>=', now()->toDateString());
            })
            ->select(['rp.permission', 'r.code as role_code', 'r.name as role_name'])
            ->get();

        /** @var array<string, list<string>> $rolesByPermission */
        $rolesByPermission = [];
        foreach ($roleRows as $row) {
            $rolesByPermission[$row->permission][] = $row->role_code . ' (' . $row->role_name . ')';
        }

        $overrides = DB::table('user_permission_override')
            ->where('company_id', $companyId)
            ->where('user_id', $userId)
            ->get()
            ->keyBy('permission');

        $result = [];
        foreach (PermissionCatalogue::codes() as $code) {
            $override = $overrides->get($code);

            if ($override !== null && $override->effect === 'deny') {
                $result[] = [
                    'permission' => $code,
                    'allowed' => false,
                    'source' => 'deny',
                    'detail' => $override->reason,
                ];
                continue;
            }

            if ($override !== null && $override->effect === 'grant') {
                $result[] = [
                    'permission' => $code,
                    'allowed' => true,
                    'source' => 'grant',
                    'detail' => $override->reason,
                ];
                continue;
            }

            if (isset($rolesByPermission[$code])) {
                $result[] = [
                    'permission' => $code,
                    'allowed' => true,
                    'source' => 'role',
                    'detail' => implode(', ', array_unique($rolesByPermission[$code])),
                ];
                continue;
            }

            $result[] = [
                'permission' => $code,
                'allowed' => false,
                'source' => 'none',
                'detail' => null,
            ];
        }

        return $result;
    }

    /**
     * Flat list of permission codes the user is currently allowed.
     *
     * @return list<string>
     */
    public function allowedCodes(AppUser $user): array
    {
        $codes = [];
        foreach ($this->forUser($user) as $row) {
            if ($row['allowed']) {
                $codes[] = $row['permission'];
            }
        }

        return $codes;
    }
}
