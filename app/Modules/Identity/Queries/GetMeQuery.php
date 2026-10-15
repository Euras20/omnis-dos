<?php

declare(strict_types=1);

namespace App\Modules\Identity\Queries;

use App\Models\AppUser;
use App\Models\Company;
use App\Modules\Controls\Queries\SystemAlertsQuery;
use App\Modules\Identity\Services\EffectivePermissionResolver;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/v1/me — current principal, company, effective permissions, location scope.
 */
final class GetMeQuery
{
    public function __construct(
        private readonly EffectivePermissionResolver $permissionResolver,
        private readonly SystemAlertsQuery $systemAlertsQuery,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(AppUser $user): array
    {
        $company = Company::query()->find($user->company_id);

        $roles = DB::table('user_role as ur')
            ->join('role as r', 'r.id', '=', 'ur.role_id')
            ->where('ur.user_id', $user->id)
            ->where('ur.company_id', $user->company_id)
            ->select([
                'r.id',
                'r.code',
                'r.name',
                'r.approval_limit_minor',
                'ur.valid_from',
                'ur.valid_to',
            ])
            ->get()
            ->map(static fn ($r) => [
                'id' => (string) $r->id,
                'code' => $r->code,
                'name' => $r->name,
                'approvalLimitMinor' => $r->approval_limit_minor !== null ? (int) $r->approval_limit_minor : null,
                'validFrom' => $r->valid_from,
                'validTo' => $r->valid_to,
            ])
            ->values()
            ->all();

        $locationIds = DB::table('user_location_scope')
            ->where('user_id', $user->id)
            ->where('company_id', $user->company_id)
            ->pluck('location_id')
            ->map(static fn ($id) => (string) $id)
            ->values()
            ->all();

        $permissions = $this->permissionResolver->allowedCodes($user);

        $alerts = $this->systemAlertsQuery->execute();

        return [
            'id' => (string) $user->id,
            'email' => $user->email,
            'displayName' => $user->display_name,
            'userType' => $user->user_type,
            'partyId' => $user->party_id !== null ? (string) $user->party_id : null,
            'employeeId' => $user->employee_id !== null ? (string) $user->employee_id : null,
            'jobTitle' => $user->job_title,
            'phone' => $user->phone,
            'isActive' => (bool) $user->is_active,
            'company' => $company === null ? null : [
                'id' => (string) $company->id,
                'code' => $company->code,
                'name' => $company->legal_name,
                'baseCurrency' => $company->functional_currency ?? 'GHS',
                'periodLockDate' => $company->period_lock_date?->format('Y-m-d'),
            ],
            'roles' => $roles,
            'permissions' => $permissions,
            'locationScope' => $locationIds,
            'alerts' => $alerts,
        ];
    }
}
