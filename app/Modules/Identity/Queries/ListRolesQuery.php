<?php

declare(strict_types=1);

namespace App\Modules\Identity\Queries;

use App\Models\Role;
use App\Shared\Company\CompanyContext;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/v1/admin/roles — roles with permission codes and active user counts.
 */
final class ListRolesQuery
{
    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {
    }

    /**
     * @return array{roles: list<array<string, mixed>>}
     */
    public function execute(): array
    {
        $companyId = $this->companyContext->companyId();

        $roles = Role::query()
            ->where('company_id', $companyId)
            ->orderBy('name')
            ->get();

        $userCounts = DB::table('user_role as ur')
            ->join('app_user as u', 'u.id', '=', 'ur.user_id')
            ->where('ur.company_id', $companyId)
            ->where('u.is_active', true)
            ->groupBy('ur.role_id')
            ->selectRaw('ur.role_id, count(*) as cnt')
            ->pluck('cnt', 'role_id');

        $permissionRows = DB::table('role_permission')
            ->whereIn('role_id', $roles->pluck('id'))
            ->get()
            ->groupBy('role_id');

        $result = [];
        foreach ($roles as $role) {
            $perms = $permissionRows->get($role->id, collect())
                ->pluck('permission')
                ->values()
                ->all();

            $result[] = [
                'id' => (string) $role->id,
                'code' => $role->code,
                'name' => $role->name,
                'description' => $role->description,
                'approvalLimitMinor' => $role->approval_limit_minor !== null
                    ? (int) $role->approval_limit_minor
                    : null,
                'isSystem' => (bool) $role->is_system,
                'isActive' => (bool) $role->is_active,
                'permissions' => $perms,
                'activeUserCount' => (int) ($userCounts[$role->id] ?? 0),
                'rowVersion' => (int) $role->row_version,
            ];
        }

        return ['roles' => $result];
    }
}
