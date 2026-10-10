<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\ConflictException;
use App\Models\Role;
use App\Models\RolePermission;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use App\Shared\Support\Uuid;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/admin/roles — create a custom role (optionally copy permissions).
 */
final class CreateRole
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly AuditWriter $auditWriter,
    ) {
    }

    /**
     * @param  array{code: string, name: string, description?: ?string, approvalLimitMinor?: ?int, permissions?: list<string>, copyFromCode?: ?string}  $data
     * @return array<string, mixed>
     */
    public function execute(array $data): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'admin.roles');

        $companyId = $this->companyContext->companyId();
        $code = strtolower(trim($data['code']));

        return DB::transaction(function () use ($data, $code, $companyId, $actorId) {
            $exists = Role::query()
                ->where('company_id', $companyId)
                ->where('code', $code)
                ->exists();

            if ($exists) {
                throw new ConflictException("A role with code '{$code}' already exists.");
            }

            $permissions = $data['permissions'] ?? [];

            if (! empty($data['copyFromCode'])) {
                $source = Role::query()
                    ->where('company_id', $companyId)
                    ->where('code', $data['copyFromCode'])
                    ->first();

                if ($source === null) {
                    throw new BusinessRuleException("Source role '{$data['copyFromCode']}' was not found.");
                }

                $permissions = RolePermission::query()
                    ->where('role_id', $source->id)
                    ->pluck('permission')
                    ->all();
            }

            $role = new Role;
            $role->id = Uuid::v7();
            $role->company_id = $companyId;
            $role->code = $code;
            $role->name = trim($data['name']);
            $role->description = $data['description'] ?? null;
            $role->approval_limit_minor = $data['approvalLimitMinor'] ?? null;
            $role->is_system = false;
            $role->is_active = true;
            $role->save();

            $this->syncPermissions($role, $permissions);

            $this->auditWriter->write(
                action: 'role.created',
                entityType: 'role',
                entityId: $role->id,
                detail: [
                    'code' => $role->code,
                    'name' => $role->name,
                    'permissions' => $permissions,
                ],
                actorId: $actorId,
            );

            return $this->toArray($role, $permissions);
        });
    }

    /**
     * @param  list<string>  $permissions
     */
    private function syncPermissions(Role $role, array $permissions): void
    {
        $unique = array_values(array_unique(array_filter($permissions, static fn ($p) => is_string($p) && $p !== '')));

        foreach ($unique as $permission) {
            RolePermission::query()->create([
                'role_id' => $role->id,
                'permission' => $permission,
            ]);
        }
    }

    /**
     * @param  list<string>  $permissions
     * @return array<string, mixed>
     */
    private function toArray(Role $role, array $permissions): array
    {
        return [
            'id' => (string) $role->id,
            'code' => $role->code,
            'name' => $role->name,
            'description' => $role->description,
            'approvalLimitMinor' => $role->approval_limit_minor !== null
                ? (int) $role->approval_limit_minor
                : null,
            'isSystem' => false,
            'isActive' => true,
            'permissions' => array_values(array_unique($permissions)),
            'activeUserCount' => 0,
            'rowVersion' => (int) $role->row_version,
        ];
    }
}
