<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Queries;

use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/v1/stock/on-hand — on hand, assigned and available by item, location, lot.
 *
 * Returns every active stockable item, including those with zero stock.
 * Items with no movements appear as a single zero row (location and lot null).
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

        $joinOn = 'sa.item_id = i.id AND sa.company_id = i.company_id';
        $joinParams = [];
        if ($locationId !== null && $locationId !== '') {
            $joinOn .= ' AND sa.location_id = ?';
            $joinParams[] = $locationId;
        }
        if ($lot !== null && $lot !== '') {
            $joinOn .= ' AND sa.lot = ?';
            $joinParams[] = $lot;
        }

        $where = [
            'i.company_id = ?',
            'i.is_active = true',
            "i.item_type <> 'Service'",
            "COALESCE(i.inventory_policy, '') <> 'Service'",
        ];
        $whereParams = [$companyId];
        if ($itemId !== null && $itemId !== '') {
            $where[] = 'i.id = ?';
            $whereParams[] = $itemId;
        }
        if ($q !== null && $q !== '') {
            $where[] = '(i.sku ILIKE ? OR i.name ILIKE ? OR sa.lot ILIKE ?)';
            $like = '%'.$q.'%';
            $whereParams[] = $like;
            $whereParams[] = $like;
            $whereParams[] = $like;
        }

        $whereSql = implode(' AND ', $where);

        $fromSql = "
            FROM item i
            LEFT JOIN stock_available sa ON {$joinOn}
            LEFT JOIN location l ON l.id = sa.location_id
            WHERE {$whereSql}
        ";

        // Join placeholders come before the WHERE placeholders.
        $params = array_merge($joinParams, $whereParams);

        $countRow = DB::selectOne("SELECT COUNT(*) AS c {$fromSql}", $params);
        $total = (int) ($countRow->c ?? 0);

        $params[] = $limit;
        $params[] = $offset;

        $rows = DB::select(
            "SELECT
                i.id AS item_id,
                i.sku,
                i.name AS item_name,
                sa.location_id,
                l.code AS location_code,
                l.name AS location_name,
                sa.lot,
                COALESCE(sa.qty_on_hand, 0) AS qty_on_hand,
                COALESCE(sa.qty_assigned, 0) AS qty_assigned,
                COALESCE(sa.qty_available, 0) AS qty_available,
                COALESCE(sa.avg_unit_cost_minor, 0) AS avg_unit_cost_minor,
                COALESCE(sa.value_minor, 0) AS value_minor
             {$fromSql}
             ORDER BY i.sku, l.code NULLS LAST, sa.lot NULLS LAST
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
