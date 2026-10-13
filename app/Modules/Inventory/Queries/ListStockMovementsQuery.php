<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Queries;

use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/v1/stock/movements and GET /api/v1/stock/ledger
 * Append-only inventory ledger rows with item/location context.
 */
final class ListStockMovementsQuery
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
        $limit = min(max((int) ($filters['limit'] ?? 50), 1), 500);
        $offset = max((int) ($filters['offset'] ?? 0), 0);

        $where = ['m.company_id = ?'];
        $params = [$companyId];

        foreach ([
            'itemId' => 'm.item_id',
            'locationId' => 'm.location_id',
            'lot' => 'm.lot',
            'movementType' => 'm.movement_type',
            'sourceType' => 'm.source_type',
            'sourceId' => 'm.source_id',
        ] as $key => $col) {
            if (! empty($filters[$key])) {
                $where[] = "{$col} = ?";
                $params[] = (string) $filters[$key];
            }
        }
        if (! empty($filters['from'])) {
            $where[] = 'm.movement_date >= ?';
            $params[] = (string) $filters['from'];
        }
        if (! empty($filters['to'])) {
            $where[] = 'm.movement_date <= ?';
            $params[] = (string) $filters['to'];
        }
        if (! empty($filters['q'])) {
            $where[] = '(i.sku ILIKE ? OR i.name ILIKE ? OR m.lot ILIKE ? OR m.memo ILIKE ?)';
            $like = '%'.((string) $filters['q']).'%';
            array_push($params, $like, $like, $like, $like);
        }

        $whereSql = implode(' AND ', $where);

        $total = (int) (DB::selectOne(
            "SELECT COUNT(*) AS c
             FROM stock_movement m
             JOIN item i ON i.id = m.item_id
             WHERE {$whereSql}",
            $params,
        )->c ?? 0);

        $params[] = $limit;
        $params[] = $offset;

        $rows = DB::select(
            "SELECT
                m.id,
                m.movement_type,
                m.item_id,
                i.sku,
                i.name AS item_name,
                m.location_id,
                l.code AS location_code,
                m.lot,
                m.qty,
                m.unit_cost_minor,
                m.movement_date,
                m.source_type,
                m.source_id,
                m.sales_order_line_id,
                m.goods_receipt_line_id,
                m.stock_assignment_id,
                m.journal_id,
                m.memo,
                m.created_by,
                m.created_at
             FROM stock_movement m
             JOIN item i ON i.id = m.item_id
             JOIN location l ON l.id = m.location_id
             WHERE {$whereSql}
             ORDER BY m.movement_date DESC, m.id DESC
             LIMIT ? OFFSET ?",
            $params,
        );

        $items = array_map(static function ($r): array {
            return [
                'id' => (int) $r->id,
                'movementType' => $r->movement_type,
                'itemId' => $r->item_id,
                'sku' => $r->sku,
                'itemName' => $r->item_name,
                'locationId' => $r->location_id,
                'locationCode' => $r->location_code,
                'lot' => $r->lot,
                'qty' => (float) $r->qty,
                'unitCostMinor' => (int) $r->unit_cost_minor,
                'movementDate' => $r->movement_date,
                'sourceType' => $r->source_type,
                'sourceId' => $r->source_id,
                'salesOrderLineId' => $r->sales_order_line_id,
                'goodsReceiptLineId' => $r->goods_receipt_line_id,
                'stockAssignmentId' => $r->stock_assignment_id,
                'journalId' => $r->journal_id,
                'memo' => $r->memo,
                'createdBy' => $r->created_by,
                'createdAt' => $r->created_at,
            ];
        }, $rows);

        return ['items' => $items, 'total' => $total];
    }
}
