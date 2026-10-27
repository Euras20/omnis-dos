<?php

declare(strict_types=1);

namespace App\Shared\Permissions;

use App\Exceptions\ForbiddenException;
use App\Exceptions\SodViolationException;
use App\Shared\Company\CompanyContext;
use Illuminate\Support\Facades\DB;

/**
 * Runtime permission check against Identity tables.
 *
 * Resolution order (06-authn-authz.md):
 * 1. Explicit user_permission_override deny → false
 * 2. Explicit user_permission_override grant → true
 * 3. Any role_permission via user_role → true
 * 4. Otherwise false
 *
 * Deny always wins. Location scope is applied by callers when the resource
 * is location-sensitive; this class answers pure permission strings.
 *
 * Permission strings follow the frontend convention: module.area.action
 * (e.g. "sales.invoices.post", "finance.journals.approve").
 */
final class PermissionChecker
{
    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {
    }

    public function can(?string $userId, string $permission): bool
    {
        if ($userId === null || $userId === '') {
            return false;
        }

        if (! $this->companyContext->isResolved()) {
            return false;
        }

        $companyId = $this->companyContext->companyId();

        try {
            // 1 & 2 — individual overrides (deny wins)
            $override = DB::table('user_permission_override')
                ->where('company_id', $companyId)
                ->where('user_id', $userId)
                ->where('permission', $permission)
                ->value('effect');

            if ($override === 'deny') {
                return false;
            }

            if ($override === 'grant') {
                return true;
            }

            // 3 — role grants
            return DB::table('user_role as ur')
                ->join('role_permission as rp', 'rp.role_id', '=', 'ur.role_id')
                ->where('ur.company_id', $companyId)
                ->where('ur.user_id', $userId)
                ->where('rp.permission', $permission)
                ->exists();
        } catch (\Throwable) {
            // Tables may not exist yet (pre-14.4). Deny by default.
            return false;
        }
    }

    /**
     * Assert permission or throw ForbiddenException.
     *
     * @throws ForbiddenException
     */
    public function assertCan(?string $userId, string $permission): void
    {
        if (! $this->can($userId, $permission)) {
            throw new ForbiddenException(
                "You do not have permission to perform this action ({$permission})."
            );
        }
    }

    /**
     * True when the user holds the system administrator role
     * (code `admin` or `system_administrator`).
     *
     * System administrators bypass runtime same-actor SoD checks so a single
     * admin account can complete every step of a workflow.
     */
    public function isSystemAdministrator(?string $userId): bool
    {
        if ($userId === null || $userId === '') {
            return false;
        }

        if (! $this->companyContext->isResolved()) {
            return false;
        }

        try {
            return DB::table('user_role as ur')
                ->join('role as r', 'r.id', '=', 'ur.role_id')
                ->where('ur.company_id', $this->companyContext->companyId())
                ->where('ur.user_id', $userId)
                ->whereIn('r.code', ['admin', 'system_administrator'])
                ->where('r.is_active', true)
                ->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Same-actor SoD helper used by posting / approval paths.
     * Returns true when the two actor IDs are different (and both non-null).
     */
    public function differentActors(?string $actorA, ?string $actorB): bool
    {
        if ($actorA === null || $actorB === null) {
            return false;
        }

        return $actorA !== $actorB;
    }

    /**
     * Assert the two actors are different or throw SodViolationException.
     *
     * @throws SodViolationException
     */
    public function assertDifferentActors(?string $actorA, ?string $actorB, ?string $message = null): void
    {
        if (! $this->differentActors($actorA, $actorB)) {
            throw new SodViolationException(
                $message ?? 'Segregation of duties: the same person may not perform both steps.'
            );
        }
    }

    /**
     * Assert the two actors are different, unless the current actor is a
     * system administrator (who may complete every step).
     *
     * @throws SodViolationException
     */
    public function assertDifferentActorsUnlessSystemAdmin(
        ?string $currentActor,
        ?string $otherActor,
        ?string $message = null,
    ): void {
        if ($this->isSystemAdministrator($currentActor)) {
            return;
        }

        $this->assertDifferentActors($currentActor, $otherActor, $message);
    }
}
