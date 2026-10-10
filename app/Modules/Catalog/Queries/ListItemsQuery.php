<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Queries;

use App\Models\Item;
use App\Modules\Catalog\Actions\CreateItem;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use Illuminate\Database\Eloquent\Builder;

/**
 * GET /api/v1/items — search items (free-stock enrichment deferred to Inventory).
 */
final class ListItemsQuery
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
    ) {
    }

    /**
     * @param  array{q?: ?string, type?: ?string, category?: ?string, status?: ?string, limit?: mixed, offset?: mixed}  $filters
     * @return array{items: list<array<string, mixed>>, total: int, limit: int, offset: int}
     */
    public function execute(array $filters): array
    {
        $this->permissionChecker->assertCan($this->companyContext->userId(), 'items.view');

        $limit = min(max((int) ($filters['limit'] ?? 50), 1), 200);
        $offset = max((int) ($filters['offset'] ?? 0), 0);

        $query = Item::query();

        if (($filters['status'] ?? null) === 'active') {
            $query->where('is_active', true);
        } elseif (($filters['status'] ?? null) === 'inactive') {
            $query->where('is_active', false);
        }

        if (! empty($filters['type'])) {
            $query->where('item_type', $filters['type']);
        }

        if (! empty($filters['category'])) {
            $query->where('category_code', strtoupper((string) $filters['category']));
        }

        if (! empty($filters['q'])) {
            $q = '%'.strtolower(trim((string) $filters['q'])).'%';
            $query->where(function (Builder $b) use ($q) {
                $b->whereRaw('LOWER(sku) LIKE ?', [$q])
                    ->orWhereRaw('LOWER(name) LIKE ?', [$q]);
            });
        }

        $total = (clone $query)->count();

        $items = $query
            ->orderBy('sku')
            ->offset($offset)
            ->limit($limit)
            ->get()
            ->map(fn (Item $i) => CreateItem::toArray($i))
            ->all();

        return [
            'items' => $items,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }
}
