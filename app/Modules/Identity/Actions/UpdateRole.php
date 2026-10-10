<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\NotFoundException;
use App\Exceptions\StaleRevisionException;
use App\Models\Role;
use App\Models\RolePermission;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use Illuminate\Support\Facades\DB;

/**
 * PUT /api/v1/admin/roles/{code} — rename and replace permissions.
 */
final class UpdateRole
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly AuditWriter $auditWriter,
    ) {
    }

    /**
     * @param  array{name?: string, description?: ?string, approvalLimitMinor?: ?int, permissions: list<string>, rowVersion: int}  $data
     * @return array<string, mixed>
     */
    public function execute(string $code, array $data): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'admin.roles');

        $companyId = $this->companyContext->companyId();

        return DB::transaction(function () use ($code, $data, $companyId, $actorId) {
            $role = Role::query()
                ->where('company_id', $companyId)
                ->where('code', $code)
                ->lockForUpdate()
                ->first();

            if ($role === null) {
                throw new NotFoundException("Role '{$code}' was not found.");
            }

            if ((int) $role->row_version !== (int) $data['rowVersion']) {
                throw new StaleRevisionException('Role was modified by another user. Reload and try again.');
            }

            // System administrator must retain admin.users + admin.roles
            if ($role->is_system && $role->code === 'system_administrator') {
                $perms = $data['permissions'] ?? [];
                if (! in_array('admin.users', $perms, true) || ! in_array('admin.roles', $perms, true)) {
                    throw new BusinessRuleException(
                        'The System administrator role must retain admin.users and admin.roles permissions.'
                    );
                }
            }

            $before = [
                'name' => $role->name,
                'permissions' => RolePermission::query()
                    ->where('role_id', $role->id)
                    ->pluck('permission')
                    ->all(),
            ];

            if (array_key_exists('name', $data) && $data['name'] !== null) {
                $role->name = trim($data['name']);
            }
            if (array_key_exists('description', $data)) {
                $role->description = $data['description'];
            }
            if (array_key_exists('approvalLimitMinor', $data)) {
                $role->approval_limit_minor = $data['approvalLimitMinor'];
            }

            $role->row_version = (int) $role->row_version + 1;
            $role->save();

            RolePermission::query()->where('role_id', $role->id)->delete();

            $permissions = array_values(array_unique(array_filter(
                $data['permissions'] ?? [],
                static fn ($p) => is_string($p) && $p !== '',
            )));

            foreach ($permissions as $permission) {
                RolePermission::query()->create([
                    'role_id' => $role->id,
                    'permission' => $permission,
                ]);
            }

            $this->auditWriter->write(
                action: 'role.updated',
                entityType: 'role',
                entityId: $role->id,
                detail: [
                    'code' => $role->code,
                    'before' => $before,
                    'after' => [
                        'name' => $role->name,
                        'permissions' => $permissions,
                    ],
                ],
                actorId: $actorId,
            );

            $activeUserCount = DB::table('user_role as ur')
                ->join('app_user as u', 'u.id', '=', 'ur.user_id')
                ->where('ur.role_id', $role->id)
                ->where('u.is_active', true)
                ->count();

            return [
                'id' => (string) $role->id,
                'code' => $role->code,
                'name' => $role->name,
                'description' => $role->description,
                'approvalLimitMinor' => $role->approval_limit_minor !== null
                    ? (int) $role->approval_limit_minor
                    : null,
                'isSystem' => (bool) $role->is_system,
                'isActive' => (bool) $role->is_active,
                'permissions' => $permissions,
                'activeUserCount' => $activeUserCount,
                'rowVersion' => (int) $role->row_version,
            ];
        });
    }
}
