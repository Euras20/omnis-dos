<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Queries;

use App\Models\StockAssignment;
use App\Modules\Inventory\Domain\AssignmentStatus;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;

/**
 * GET /api/v1/stock/assignments — open assignments (pending + assigned by default).
 */
final class ListAssignmentsQuery
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
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'stock.view');

        $companyId = $this->companyContext->companyId();
        $status = isset($filters['status']) ? (string) $filters['status'] : null;
        $itemId = isset($filters['itemId']) ? (string) $filters['itemId'] : null;
        $locationId = isset($filters['locationId']) ? (string) $filters['locationId'] : null;
        $salesOrderLineId = isset($filters['salesOrderLineId']) ? (string) $filters['salesOrderLineId'] : null;
        $limit = min(max((int) ($filters['limit'] ?? 100), 1), 500);
        $offset = max((int) ($filters['offset'] ?? 0), 0);

        $query = StockAssignment::query()
            ->where('company_id', $companyId)
            ->with(['item:id,sku,name', 'location:id,code,name']);

        if ($status !== null && $status !== '') {
            $query->where('status', $status);
        } else {
            $query->whereIn('status', AssignmentStatus::open());
        }

        if ($itemId !== null && $itemId !== '') {
            $query->where('item_id', $itemId);
        }
        if ($locationId !== null && $locationId !== '') {
            $query->where('location_id', $locationId);
        }
        if ($salesOrderLineId !== null && $salesOrderLineId !== '') {
            $query->where('sales_order_line_id', $salesOrderLineId);
        }

        $total = (clone $query)->count();

        $rows = $query
            ->orderByDesc('created_at')
            ->limit($limit)
            ->offset($offset)
            ->get();

        $items = $rows->map(static function (StockAssignment $a): array {
            return [
                'id' => $a->id,
                'itemId' => $a->item_id,
                'sku' => $a->item?->sku,
                'itemName' => $a->item?->name,
                'locationId' => $a->location_id,
                'locationCode' => $a->location?->code,
                'locationName' => $a->location?->name,
                'lot' => $a->lot,
                'qty' => (string) $a->qty,
                'status' => $a->status,
                'salesOrderLineId' => $a->sales_order_line_id,
                'requestedBy' => $a->requested_by,
                'decidedBy' => $a->decided_by,
                'decidedAt' => $a->decided_at?->toIso8601String(),
                'decisionNote' => $a->decision_note,
                'memo' => $a->memo,
                'createdAt' => $a->created_at?->toIso8601String(),
            ];
        })->all();

        return ['items' => $items, 'total' => $total];
    }
}
