<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\ConflictException;
use App\Models\AppUser;
use App\Models\Role;
use App\Models\UserLocationScope;
use App\Models\UserRole;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use App\Shared\Support\Uuid;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/admin/users — provision a staff or portal user.
 */
final class CreateUser
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly AuditWriter $auditWriter,
    ) {
    }

    /**
     * @param  array{
     *     email: string,
     *     displayName: string,
     *     userType?: string,
     *     partyId?: ?string,
     *     jobTitle?: ?string,
     *     phone?: ?string,
     *     externalSubject?: ?string,
     *     roleCodes?: list<string>,
     *     locationIds?: list<string>
     * }  $data
     * @return array<string, mixed>
     */
    public function execute(array $data): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'admin.users');

        $companyId = $this->companyContext->companyId();
        $email = strtolower(trim($data['email']));
        $userType = $data['userType'] ?? 'staff';

        if (! in_array($userType, ['staff', 'portal'], true)) {
            throw new BusinessRuleException('userType must be staff or portal.');
        }

        if ($userType === 'portal' && empty($data['partyId'])) {
            throw new BusinessRuleException('Portal users must be linked to a partyId.');
        }

        return DB::transaction(function () use ($data, $email, $userType, $companyId, $actorId) {
            $exists = AppUser::query()
                ->where('company_id', $companyId)
                ->where('email', $email)
                ->exists();

            if ($exists) {
                throw new ConflictException("A user with email '{$email}' already exists.");
            }

            $user = new AppUser;
            $user->id = Uuid::v7();
            $user->company_id = $companyId;
            $user->email = $email;
            $user->display_name = trim($data['displayName']);
            $user->user_type = $userType;
            $user->party_id = $data['partyId'] ?? null;
            $user->job_title = $data['jobTitle'] ?? null;
            $user->phone = $data['phone'] ?? null;
            $user->external_subject = $data['externalSubject'] ?? null;
            $user->is_active = true;
            $user->created_by = $actorId;
            $user->save();

            $roleCodes = $data['roleCodes'] ?? [];
            $assignedRoles = $this->assignRoles($user, $roleCodes, $companyId);

            if ($userType === 'portal') {
                $this->assertPortalRolesOnly($assignedRoles);
            }

            $locationIds = $data['locationIds'] ?? [];
            $this->syncLocations($user, $locationIds, $companyId);

            $this->auditWriter->write(
                action: 'user.created',
                entityType: 'app_user',
                entityId: $user->id,
                detail: [
                    'email' => $user->email,
                    'displayName' => $user->display_name,
                    'userType' => $user->user_type,
                    'roleCodes' => $roleCodes,
                    'locationIds' => $locationIds,
                ],
                actorId: $actorId,
            );

            return $this->toArray($user, $assignedRoles, $locationIds);
        });
    }

    /**
     * @param  list<string>  $roleCodes
     * @return list<array{id: string, code: string, name: string}>
     */
    private function assignRoles(AppUser $user, array $roleCodes, string $companyId): array
    {
        $assigned = [];

        foreach (array_unique($roleCodes) as $code) {
            $role = Role::query()
                ->where('company_id', $companyId)
                ->where('code', $code)
                ->where('is_active', true)
                ->first();

            if ($role === null) {
                throw new BusinessRuleException("Role '{$code}' was not found or is inactive.");
            }

            UserRole::query()->create([
                'id' => Uuid::v7(),
                'company_id' => $companyId,
                'user_id' => $user->id,
                'role_id' => $role->id,
            ]);

            $assigned[] = [
                'id' => (string) $role->id,
                'code' => $role->code,
                'name' => $role->name,
            ];
        }

        return $assigned;
    }

    /**
     * @param  list<array{code: string}>  $roles
     */
    private function assertPortalRolesOnly(array $roles): void
    {
        foreach ($roles as $role) {
            if (! str_starts_with($role['code'], 'portal_') && ! in_array($role['code'], ['customer_portal', 'supplier_portal'], true)) {
                // Soft rule: portal users should only hold portal-oriented roles.
                // Allow any role whose code contains "portal".
                if (! str_contains($role['code'], 'portal')) {
                    throw new BusinessRuleException(
                        "Portal users may only hold portal roles (got '{$role['code']}')."
                    );
                }
            }
        }
    }

    /**
     * @param  list<string>  $locationIds
     */
    private function syncLocations(AppUser $user, array $locationIds, string $companyId): void
    {
        foreach (array_unique($locationIds) as $locationId) {
            UserLocationScope::query()->create([
                'company_id' => $companyId,
                'user_id' => $user->id,
                'location_id' => $locationId,
            ]);
        }
    }

    /**
     * @param  list<array{id: string, code: string, name: string}>  $roles
     * @param  list<string>  $locationIds
     * @return array<string, mixed>
     */
    private function toArray(AppUser $user, array $roles, array $locationIds): array
    {
        return [
            'id' => (string) $user->id,
            'email' => $user->email,
            'displayName' => $user->display_name,
            'userType' => $user->user_type,
            'partyId' => $user->party_id !== null ? (string) $user->party_id : null,
            'jobTitle' => $user->job_title,
            'phone' => $user->phone,
            'isActive' => true,
            'roles' => $roles,
            'locationScope' => array_values($locationIds),
            'overrideCount' => 0,
            'rowVersion' => (int) $user->row_version,
        ];
    }
}
