<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Queries;

use App\Models\AppUser;
use App\Models\Party;
use App\Modules\Catalog\Actions\CreateParty;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use Illuminate\Database\Eloquent\Builder;

/**
 * GET /api/v1/parties — search parties filtered by types the caller may view.
 */
final class ListPartiesQuery
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
    ) {
    }

    /**
     * @param  array{q?: ?string, type?: ?string, status?: ?string, limit?: mixed, offset?: mixed}  $filters
     * @return array{items: list<array<string, mixed>>, total: int, limit: int, offset: int}
     */
    public function execute(array $filters): array
    {
        $actorId = $this->companyContext->userId();
        $allowedTypes = $this->allowedTypes($actorId);

        if ($allowedTypes === []) {
            return ['items' => [], 'total' => 0, 'limit' => 50, 'offset' => 0];
        }

        $limit = min(max((int) ($filters['limit'] ?? 50), 1), 200);
        $offset = max((int) ($filters['offset'] ?? 0), 0);

        $query = Party::query()->whereIn('party_type', $allowedTypes);

        // Portal users only see their own party.
        $actor = AppUser::query()->find($actorId);
        if ($actor !== null && $actor->user_type === 'portal' && $actor->party_id !== null) {
            $query->where('id', $actor->party_id);
        }

        if (! empty($filters['type'])) {
            $requested = array_map('trim', explode(',', (string) $filters['type']));
            $requested = array_values(array_intersect($requested, $allowedTypes));
            if ($requested !== []) {
                $query->whereIn('party_type', $requested);
            }
        }

        if (($filters['status'] ?? null) === 'active') {
            $query->where('is_active', true);
        } elseif (($filters['status'] ?? null) === 'inactive') {
            $query->where('is_active', false);
        }

        if (! empty($filters['q'])) {
            $q = '%'.strtolower(trim((string) $filters['q'])).'%';
            $query->where(function (Builder $b) use ($q) {
                $b->whereRaw('LOWER(name) LIKE ?', [$q])
                    ->orWhereRaw('LOWER(code) LIKE ?', [$q])
                    ->orWhereRaw('LOWER(COALESCE(email, \'\')) LIKE ?', [$q]);
            });
        }

        $total = (clone $query)->count();

        $items = $query
            ->orderBy('name')
            ->offset($offset)
            ->limit($limit)
            ->get()
            ->map(fn (Party $p) => CreateParty::toArray($p))
            ->all();

        return [
            'items' => $items,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * @return list<string>
     */
    private function allowedTypes(string $actorId): array
    {
        $types = [];

        if ($this->permissionChecker->can($actorId, 'customers.view')
            || $this->permissionChecker->can($actorId, 'customers.manage')
            || $this->permissionChecker->can($actorId, 'sales.quotes.manage')
            || $this->permissionChecker->can($actorId, 'sales.quotes.view')
            || $this->permissionChecker->can($actorId, 'sales.orders.manage')
            || $this->permissionChecker->can($actorId, 'sales.orders.view')) {
            $types[] = 'customer';
        }

        if ($this->permissionChecker->can($actorId, 'suppliers.view')
            || $this->permissionChecker->can($actorId, 'suppliers.manage')) {
            $types[] = 'supplier';
            $types[] = 'carrier';
            $types[] = 'broker';
        }

        // Logistics staff need carriers and brokers for shipment / customs selects
        // even if they do not hold supplier permissions.
        if ($this->permissionChecker->can($actorId, 'logistics.view')
            || $this->permissionChecker->can($actorId, 'logistics.manage')
            || $this->permissionChecker->can($actorId, 'logistics.customs')) {
            if (! in_array('carrier', $types, true)) {
                $types[] = 'carrier';
            }
            if (! in_array('broker', $types, true)) {
                $types[] = 'broker';
            }
        }

        return $types;
    }
}
