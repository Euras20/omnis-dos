<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Queries;

use App\Models\StockCount;
use App\Modules\Inventory\Actions\CreateStockCount;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;

/** GET /api/v1/stock/counts · GET /api/v1/stock/counts/{id} */
final class ListStockCountsQuery
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
    ) {
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function execute(array $filters = []): array
    {
        $this->permissionChecker->assertCan($this->companyContext->userId(), 'stock.view');

        $limit = min(max((int) ($filters['limit'] ?? 50), 1), 200);
        $offset = max((int) ($filters['offset'] ?? 0), 0);

        $q = StockCount::query()->with(['location']);

        if (! empty($filters['status'])) {
            $q->where('status', (string) $filters['status']);
        }
        if (! empty($filters['locationId'])) {
            $q->where('location_id', (string) $filters['locationId']);
        }

        $total = (clone $q)->count();
        $rows = $q->orderByDesc('count_date')->orderByDesc('created_at')
            ->offset($offset)->limit($limit)->get();

        $items = $rows->map(static function (StockCount $c): array {
            return [
                'id' => $c->id,
                'countNo' => $c->count_no,
                'locationId' => $c->location_id,
                'locationCode' => $c->location?->code,
                'countDate' => $c->count_date?->format('Y-m-d'),
                'status' => $c->status,
                'countedBy' => $c->counted_by,
                'approvedBy' => $c->approved_by,
                'memo' => $c->memo,
                'createdAt' => $c->created_at?->toIso8601String(),
            ];
        })->values()->all();

        return ['items' => $items, 'total' => $total];
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $id): array
    {
        $this->permissionChecker->assertCan($this->companyContext->userId(), 'stock.view');

        $count = StockCount::query()->with(['lines.item', 'location'])->where('id', $id)->firstOrFail();

        return CreateStockCount::toArray($count);
    }
}
