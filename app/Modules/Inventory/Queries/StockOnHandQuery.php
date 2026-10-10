<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Queries;

use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/v1/stock/on-hand — on hand, assigned and available by item, location, lot.
 */
final class StockOnHandQuery
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
        $itemId = isset($filters['itemId']) ? (string) $filters['itemId'] : null;
        $locationId = isset($filters['locationId']) ? (string) $filters['locationId'] : null;
        $lot = isset($filters['lot']) ? (string) $filters['lot'] : null;
        $q = isset($filters['q']) ? trim((string) $filters['q']) : null;
        $limit = min(max((int) ($filters['limit'] ?? 100), 1), 500);
        $offset = max((int) ($filters['offset'] ?? 0), 0);

        $params = [$companyId];
        $where = ['sa.company_id = ?'];

        if ($itemId !== null && $itemId !== '') {
            $where[] = 'sa.item_id = ?';
            $params[] = $itemId;
        }
        if ($locationId !== null && $locationId !== '') {
            $where[] = 'sa.location_id = ?';
            $params[] = $locationId;
        }
        if ($lot !== null && $lot !== '') {
            $where[] = 'sa.lot = ?';
            $params[] = $lot;
        }
        if ($q !== null && $q !== '') {
            $where[] = '(i.sku ILIKE ? OR i.name ILIKE ? OR sa.lot ILIKE ?)';
            $like = '%'.$q.'%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $whereSql = implode(' AND ', $where);

        $countRow = DB::selectOne(
            "SELECT COUNT(*) AS c
             FROM stock_available sa
             JOIN item i ON i.id = sa.item_id
             WHERE {$whereSql}",
            $params,
        );
        $total = (int) ($countRow->c ?? 0);

        $params[] = $limit;
        $params[] = $offset;

        $rows = DB::select(
            "SELECT
                sa.item_id,
                i.sku,
                i.name AS item_name,
                sa.location_id,
                l.code AS location_code,
                l.name AS location_name,
                sa.lot,
                sa.qty_on_hand,
                sa.qty_assigned,
                sa.qty_available,
                sa.avg_unit_cost_minor,
                sa.value_minor
             FROM stock_available sa
             JOIN item i ON i.id = sa.item_id
             JOIN location l ON l.id = sa.location_id
             WHERE {$whereSql}
             ORDER BY i.sku, l.code, sa.lot
             LIMIT ? OFFSET ?",
            $params,
        );

        $items = array_map(static function ($row): array {
            return [
                'itemId' => $row->item_id,
                'sku' => $row->sku,
                'itemName' => $row->item_name,
                'locationId' => $row->location_id,
                'locationCode' => $row->location_code,
                'locationName' => $row->location_name,
                'lot' => $row->lot,
                'qtyOnHand' => (string) $row->qty_on_hand,
                'qtyAssigned' => (string) $row->qty_assigned,
                'qtyAvailable' => (string) $row->qty_available,
                'avgUnitCostMinor' => (int) $row->avg_unit_cost_minor,
                'valueMinor' => (int) $row->value_minor,
            ];
        }, $rows);

        return ['items' => $items, 'total' => $total];
    }
}
