<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Queries;

use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/v1/stock/low-stock — items whose available quantity is at or below 50% of reorder point.
 *
 * In-system low-stock alert. Threshold is fixed at 50% of the item's reorder_point.
 * Only items with a positive reorder_point are considered. Quantity is the sum of
 * qty_available across all locations and lots (from stock_available view).
 */
final class LowStockAlertQuery
{
    private const THRESHOLD_PERCENT = 50;

    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
    ) {
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{items: list<array<string, mixed>>, total: int, thresholdPercent: int}
     */
    public function execute(array $filters = []): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'stock.view');

        $companyId = $this->companyContext->companyId();
        $q = isset($filters['q']) ? trim((string) $filters['q']) : null;
        $limit = min(max((int) ($filters['limit'] ?? 100), 1), 500);
        $offset = max((int) ($filters['offset'] ?? 0), 0);

        $params = [$companyId];
        $where = [
            'i.company_id = ?',
            'i.reorder_point IS NOT NULL',
            'i.reorder_point > 0',
            'i.is_active = true',
        ];

        if ($q !== null && $q !== '') {
            $where[] = '(i.sku ILIKE ? OR i.name ILIKE ?)';
            $like = '%'.$q.'%';
            $params[] = $like;
            $params[] = $like;
        }

        $whereSql = implode(' AND ', $where);

        // Aggregate available qty per item, then filter to those at or below 50% of reorder.
        $sql = "
            SELECT
                i.id AS item_id,
                i.sku,
                i.name AS item_name,
                i.reorder_point,
                COALESCE(SUM(sa.qty_available), 0) AS qty_available
            FROM item i
            LEFT JOIN stock_available sa
                ON sa.item_id = i.id AND sa.company_id = i.company_id
            WHERE {$whereSql}
            GROUP BY i.id, i.sku, i.name, i.reorder_point
            HAVING COALESCE(SUM(sa.qty_available), 0) <= (i.reorder_point * 0.5)
            ORDER BY i.sku
        ";

        $countSql = "SELECT COUNT(*) AS c FROM ({$sql}) low_stock";
        $countRow = DB::selectOne($countSql, $params);
        $total = (int) ($countRow->c ?? 0);

        $params[] = $limit;
        $params[] = $offset;

        $rows = DB::select($sql.' LIMIT ? OFFSET ?', $params);

        $items = array_map(static function ($row): array {
            $reorder = (float) $row->reorder_point;
            $available = (float) $row->qty_available;
            $threshold = $reorder * (self::THRESHOLD_PERCENT / 100);
            $percent = $reorder > 0 ? round(($available / $reorder) * 100, 1) : 0.0;

            return [
                'itemId' => $row->item_id,
                'sku' => $row->sku,
                'itemName' => $row->item_name,
                'reorderPoint' => number_format($reorder, 3, '.', ''),
                'qtyAvailable' => number_format($available, 3, '.', ''),
                'thresholdQty' => number_format($threshold, 3, '.', ''),
                'percentOfReorder' => $percent,
                'isLowStock' => true,
            ];
        }, $rows);

        return [
            'items' => $items,
            'total' => $total,
            'thresholdPercent' => self::THRESHOLD_PERCENT,
        ];
    }
}
