<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Queries;

use App\Models\StockTransfer;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;

/** GET /api/v1/stock/transfers */
final class ListStockTransfersQuery
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

        $q = StockTransfer::query()->with(['item', 'fromLocation', 'toLocation']);

        if (! empty($filters['itemId'])) {
            $q->where('item_id', (string) $filters['itemId']);
        }
        if (! empty($filters['fromLocationId'])) {
            $q->where('from_location_id', (string) $filters['fromLocationId']);
        }
        if (! empty($filters['toLocationId'])) {
            $q->where('to_location_id', (string) $filters['toLocationId']);
        }
        if (! empty($filters['status'])) {
            $q->where('status', (string) $filters['status']);
        }

        $total = (clone $q)->count();
        $rows = $q->orderByDesc('transfer_date')->orderByDesc('created_at')
            ->offset($offset)->limit($limit)->get();

        $items = $rows->map(static function (StockTransfer $t): array {
            return [
                'id' => $t->id,
                'transferNo' => $t->transfer_no,
                'itemId' => $t->item_id,
                'sku' => $t->item?->sku,
                'itemName' => $t->item?->name,
                'fromLocationId' => $t->from_location_id,
                'fromLocationCode' => $t->fromLocation?->code,
                'toLocationId' => $t->to_location_id,
                'toLocationCode' => $t->toLocation?->code,
                'lot' => $t->lot,
                'qty' => (float) $t->qty,
                'transferDate' => $t->transfer_date?->format('Y-m-d'),
                'status' => $t->status,
                'memo' => $t->memo,
                'createdAt' => $t->created_at?->toIso8601String(),
            ];
        })->values()->all();

        return ['items' => $items, 'total' => $total];
    }
}
