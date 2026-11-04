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
     * @param  array<string, mixed>  $filters
     * @return array{items: list<array<string, mixed>>, total: int, limit: int, offset: int}
     */
    public function execute(array $filters): array
    {
        $this->permissionChecker->assertCan($this->companyContext->userId(), 'items.view');

        $limit = min(max((int) ($filters['limit'] ?? 50), 1), 200);
        $offset = max((int) ($filters['offset'] ?? 0), 0);

        $query = Item::query();

        $status = isset($filters['status']) ? strtolower(trim((string) $filters['status'])) : '';
        $isActive = $filters['isActive'] ?? null;
        if ($status === 'active' || $isActive === true || $isActive === 'true' || $isActive === '1') {
            $query->where('is_active', true);
        } elseif ($status === 'inactive' || $isActive === false || $isActive === 'false' || $isActive === '0') {
            $query->where('is_active', false);
        }

        $type = $filters['type'] ?? $filters['itemType'] ?? null;
        if (! empty($type)) {
            $query->where('item_type', (string) $type);
        }

        $category = $filters['category'] ?? $filters['categoryCode'] ?? null;
        if (! empty($category)) {
            $query->where('category_code', strtoupper((string) $category));
        }

        $locationId = $filters['locationId'] ?? $filters['defaultLocationId'] ?? null;
        if (! empty($locationId)) {
            $query->where('default_location_id', (string) $locationId);
        }

        $supplierId = $filters['supplierId'] ?? $filters['defaultSupplierId'] ?? null;
        if (! empty($supplierId)) {
            $query->where('default_supplier_id', (string) $supplierId);
        }

        $policy = $filters['inventoryPolicy'] ?? $filters['policy'] ?? null;
        if (! empty($policy)) {
            $query->where('inventory_policy', (string) $policy);
        }

        $lotControlled = $filters['lotControlled'] ?? null;
        if ($lotControlled === true || $lotControlled === 'true' || $lotControlled === '1') {
            $query->where('lot_controlled', true);
        } elseif ($lotControlled === false || $lotControlled === 'false' || $lotControlled === '0') {
            $query->where('lot_controlled', false);
        }

        if (! empty($filters['sku'])) {
            $query->whereRaw('LOWER(sku) LIKE ?', ['%'.strtolower(trim((string) $filters['sku'])).'%']);
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
