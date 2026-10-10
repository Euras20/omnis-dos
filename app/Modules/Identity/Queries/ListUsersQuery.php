<?php

declare(strict_types=1);

namespace App\Modules\Identity\Queries;

use App\Models\AppUser;
use App\Shared\Company\CompanyContext;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/v1/admin/users — users with roles, location scope, overrides, status.
 */
final class ListUsersQuery
{
    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {
    }

    /**
     * @param  array{q?: string, status?: string, limit?: int, offset?: int}  $filters
     * @return array{users: list<array<string, mixed>>, total: int}
     */
    public function execute(array $filters = []): array
    {
        $companyId = $this->companyContext->companyId();
        $limit = min(max((int) ($filters['limit'] ?? 50), 1), 200);
        $offset = max((int) ($filters['offset'] ?? 0), 0);

        $query = AppUser::query()->where('company_id', $companyId);

        if (! empty($filters['q'])) {
            $q = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $filters['q']) . '%';
            $query->where(function ($builder) use ($q) {
                $builder->where('email', 'ilike', $q)
                    ->orWhere('display_name', 'ilike', $q);
            });
        }

        if (($filters['status'] ?? null) === 'active') {
            $query->where('is_active', true);
        } elseif (($filters['status'] ?? null) === 'inactive') {
            $query->where('is_active', false);
        }

        $total = (clone $query)->count();

        $users = $query
            ->orderBy('display_name')
            ->limit($limit)
            ->offset($offset)
            ->get();

        $userIds = $users->pluck('id')->all();

        $roleRows = DB::table('user_role as ur')
            ->join('role as r', 'r.id', '=', 'ur.role_id')
            ->whereIn('ur.user_id', $userIds)
            ->select(['ur.user_id', 'r.id', 'r.code', 'r.name', 'ur.valid_from', 'ur.valid_to'])
            ->get()
            ->groupBy('user_id');

        $locationRows = DB::table('user_location_scope')
            ->whereIn('user_id', $userIds)
            ->get()
            ->groupBy('user_id');

        $overrideCounts = DB::table('user_permission_override')
            ->whereIn('user_id', $userIds)
            ->groupBy('user_id')
            ->selectRaw('user_id, count(*) as cnt')
            ->pluck('cnt', 'user_id');

        $result = [];
        foreach ($users as $user) {
            $roles = ($roleRows->get($user->id) ?? collect())
                ->map(static fn ($r) => [
                    'id' => (string) $r->id,
                    'code' => $r->code,
                    'name' => $r->name,
                    'validFrom' => $r->valid_from,
                    'validTo' => $r->valid_to,
                ])
                ->values()
                ->all();

            $locations = ($locationRows->get($user->id) ?? collect())
                ->pluck('location_id')
                ->map(static fn ($id) => (string) $id)
                ->values()
                ->all();

            $result[] = [
                'id' => (string) $user->id,
                'email' => $user->email,
                'displayName' => $user->display_name,
                'userType' => $user->user_type,
                'partyId' => $user->party_id !== null ? (string) $user->party_id : null,
                'jobTitle' => $user->job_title,
                'phone' => $user->phone,
                'isActive' => (bool) $user->is_active,
                'lastSeenAt' => $user->last_seen_at?->toIso8601String(),
                'deactivatedAt' => $user->deactivated_at?->toIso8601String(),
                'roles' => $roles,
                'locationScope' => $locations,
                'overrideCount' => (int) ($overrideCounts[$user->id] ?? 0),
                'rowVersion' => (int) $user->row_version,
            ];
        }

        return ['users' => $result, 'total' => $total];
    }
}
