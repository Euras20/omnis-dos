<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\NotFoundException;
use App\Models\Role;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use Illuminate\Support\Facades\DB;

/**
 * DELETE /api/v1/admin/roles/{code} — delete a custom role that no user holds.
 */
final class DeleteRole
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly AuditWriter $auditWriter,
    ) {
    }

    public function execute(string $code): void
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'admin.roles');

        $companyId = $this->companyContext->companyId();

        DB::transaction(function () use ($code, $companyId, $actorId) {
            $role = Role::query()
                ->where('company_id', $companyId)
                ->where('code', $code)
                ->lockForUpdate()
                ->first();

            if ($role === null) {
                throw new NotFoundException("Role '{$code}' was not found.");
            }

            if ($role->is_system) {
                throw new BusinessRuleException('System roles cannot be deleted.');
            }

            $holders = DB::table('user_role')
                ->where('role_id', $role->id)
                ->count();

            if ($holders > 0) {
                throw new BusinessRuleException(
                    'Cannot delete a role that is still assigned to users. Remove the role from all users first.'
                );
            }

            $roleId = $role->id;
            $roleCode = $role->code;
            $roleName = $role->name;

            // role_permission cascades via FK
            $role->delete();

            $this->auditWriter->write(
                action: 'role.deleted',
                entityType: 'role',
                entityId: $roleId,
                detail: [
                    'code' => $roleCode,
                    'name' => $roleName,
                ],
                actorId: $actorId,
            );
        });
    }
}
