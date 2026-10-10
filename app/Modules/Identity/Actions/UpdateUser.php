<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\NotFoundException;
use App\Exceptions\StaleRevisionException;
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
 * PATCH /api/v1/admin/users/{id} — edit details, roles, location scope, activate/deactivate.
 */
final class UpdateUser
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly AuditWriter $auditWriter,
    ) {
    }

    /**
     * @param  array{
     *     displayName?: string,
     *     jobTitle?: ?string,
     *     phone?: ?string,
     *     roleCodes?: list<string>,
     *     locationIds?: list<string>,
     *     isActive?: bool,
     *     deactivationReason?: ?string,
     *     rowVersion: int
     * }  $data
     * @return array<string, mixed>
     */
    public function execute(string $userId, array $data): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'admin.users');

        $companyId = $this->companyContext->companyId();

        return DB::transaction(function () use ($userId, $data, $companyId, $actorId) {
            $user = AppUser::query()
                ->where('company_id', $companyId)
                ->where('id', $userId)
                ->lockForUpdate()
                ->first();

            if ($user === null) {
                throw new NotFoundException('User not found.');
            }

            if ((int) $user->row_version !== (int) $data['rowVersion']) {
                throw new StaleRevisionException('User was modified by another user. Reload and try again.');
            }

            // Cannot deactivate yourself
            if (array_key_exists('isActive', $data)
                && $data['isActive'] === false
                && $user->id === $actorId
            ) {
                throw new BusinessRuleException('You cannot deactivate your own account.');
            }

            if (array_key_exists('displayName', $data) && $data['displayName'] !== null) {
                $user->display_name = trim($data['displayName']);
            }
            if (array_key_exists('jobTitle', $data)) {
                $user->job_title = $data['jobTitle'];
            }
            if (array_key_exists('phone', $data)) {
                $user->phone = $data['phone'];
            }

            if (array_key_exists('isActive', $data)) {
                if ($data['isActive'] === false && $user->is_active) {
                    if (empty($data['deactivationReason'])) {
                        throw new BusinessRuleException('A deactivation reason is required.');
                    }
                    $user->is_active = false;
                    $user->deactivated_at = now();
                    $user->deactivation_reason = $data['deactivationReason'];
                    $this->assertRemainingAdmin($companyId, $user->id);
                } elseif ($data['isActive'] === true && ! $user->is_active) {
                    $user->is_active = true;
                    $user->deactivated_at = null;
                    $user->deactivation_reason = null;
                }
            }

            $user->row_version = (int) $user->row_version + 1;
            $user->save();

            $roles = null;
            if (array_key_exists('roleCodes', $data)) {
                // Cannot strip own admin access
                if ($user->id === $actorId) {
                    $this->assertKeepsAdminAccess($data['roleCodes'] ?? [], $companyId);
                }
                $roles = $this->replaceRoles($user, $data['roleCodes'] ?? [], $companyId);
            }

            $locations = null;
            if (array_key_exists('locationIds', $data)) {
                $locations = $this->replaceLocations($user, $data['locationIds'] ?? [], $companyId);
            }

            if ($roles === null) {
                $roles = $this->currentRoles($user);
            }
            if ($locations === null) {
                $locations = UserLocationScope::query()
                    ->where('user_id', $user->id)
                    ->pluck('location_id')
                    ->map(static fn ($id) => (string) $id)
                    ->all();
            }

            $this->auditWriter->write(
                action: 'user.updated',
                entityType: 'app_user',
                entityId: $user->id,
                detail: [
                    'email' => $user->email,
                    'isActive' => $user->is_active,
                    'roleCodes' => array_column($roles, 'code'),
                    'locationIds' => $locations,
                ],
                actorId: $actorId,
            );

            $overrideCount = DB::table('user_permission_override')
                ->where('user_id', $user->id)
                ->count();

            return [
                'id' => (string) $user->id,
                'email' => $user->email,
                'displayName' => $user->display_name,
                'userType' => $user->user_type,
                'partyId' => $user->party_id !== null ? (string) $user->party_id : null,
                'jobTitle' => $user->job_title,
                'phone' => $user->phone,
                'isActive' => (bool) $user->is_active,
                'deactivatedAt' => $user->deactivated_at?->toIso8601String(),
                'roles' => $roles,
                'locationScope' => $locations,
                'overrideCount' => $overrideCount,
                'rowVersion' => (int) $user->row_version,
            ];
        });
    }

    /**
     * Ensure at least one other active user still has admin.users.
     */
    private function assertRemainingAdmin(string $companyId, string $excludingUserId): void
    {
        $remaining = DB::table('user_role as ur')
            ->join('role_permission as rp', 'rp.role_id', '=', 'ur.role_id')
            ->join('app_user as u', 'u.id', '=', 'ur.user_id')
            ->where('ur.company_id', $companyId)
            ->where('u.is_active', true)
            ->where('u.id', '!=', $excludingUserId)
            ->where('rp.permission', 'admin.users')
            ->exists();

        // Also count explicit grants
        if (! $remaining) {
            $remaining = DB::table('user_permission_override as o')
                ->join('app_user as u', 'u.id', '=', 'o.user_id')
                ->where('o.company_id', $companyId)
                ->where('o.permission', 'admin.users')
                ->where('o.effect', 'grant')
                ->where('u.is_active', true)
                ->where('u.id', '!=', $excludingUserId)
                ->exists();
        }

        if (! $remaining) {
            throw new BusinessRuleException(
                'Cannot deactivate the last active user who can manage users.'
            );
        }
    }

    /**
     * @param  list<string>  $roleCodes
     */
    private function assertKeepsAdminAccess(array $roleCodes, string $companyId): void
    {
        $hasAdmin = Role::query()
            ->where('company_id', $companyId)
            ->whereIn('code', $roleCodes)
            ->whereHas('permissions', static function ($q) {
                $q->where('permission', 'admin.users');
            })
            ->exists();

        if (! $hasAdmin) {
            throw new BusinessRuleException(
                'You cannot remove your own ability to manage users.'
            );
        }
    }

    /**
     * @param  list<string>  $roleCodes
     * @return list<array{id: string, code: string, name: string}>
     */
    private function replaceRoles(AppUser $user, array $roleCodes, string $companyId): array
    {
        UserRole::query()->where('user_id', $user->id)->delete();

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
     * @param  list<string>  $locationIds
     * @return list<string>
     */
    private function replaceLocations(AppUser $user, array $locationIds, string $companyId): array
    {
        UserLocationScope::query()->where('user_id', $user->id)->delete();

        $unique = array_values(array_unique($locationIds));
        foreach ($unique as $locationId) {
            UserLocationScope::query()->create([
                'company_id' => $companyId,
                'user_id' => $user->id,
                'location_id' => $locationId,
            ]);
        }

        return $unique;
    }

    /**
     * @return list<array{id: string, code: string, name: string}>
     */
    private function currentRoles(AppUser $user): array
    {
        return DB::table('user_role as ur')
            ->join('role as r', 'r.id', '=', 'ur.role_id')
            ->where('ur.user_id', $user->id)
            ->select(['r.id', 'r.code', 'r.name'])
            ->get()
            ->map(static fn ($r) => [
                'id' => (string) $r->id,
                'code' => $r->code,
                'name' => $r->name,
            ])
            ->all();
    }
}
