<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Queries;

use App\Exceptions\BusinessRuleException;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/v1/stock/traceability/{lot} — lot history across movements + linked docs.
 */
final class TraceabilityQuery
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(string $lot): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'stock.view');

        $lot = trim($lot);
        if ($lot === '') {
            throw new BusinessRuleException('lot is required.');
        }

        $companyId = $this->companyContext->companyId();

        $movements = DB::select(
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
                m.journal_id,
                m.memo,
                m.created_at
             FROM stock_movement m
             JOIN item i ON i.id = m.item_id
             JOIN location l ON l.id = m.location_id
             WHERE m.company_id = ? AND m.lot = ?
             ORDER BY m.movement_date ASC, m.id ASC",
            [$companyId, $lot],
        );

        $onHand = DB::select(
            "SELECT location_id, l.code AS location_code, qty_on_hand
             FROM stock_on_hand oh
             JOIN location l ON l.id = oh.location_id
             WHERE oh.company_id = ? AND oh.lot = ?
             ORDER BY l.code",
            [$companyId, $lot],
        );

        $chain = [];
        $seen = [];
        foreach ($movements as $m) {
            $key = ($m->source_type ?? '').':'.($m->source_id ?? '');
            if ($m->source_type && $m->source_id && ! isset($seen[$key])) {
                $seen[$key] = true;
                $chain[] = [
                    'sourceType' => $m->source_type,
                    'sourceId' => $m->source_id,
                    'movementType' => $m->movement_type,
                    'date' => $m->movement_date,
                ];
            }
        }

        return [
            'lot' => $lot,
            'onHand' => array_map(static fn ($r) => [
                'locationId' => $r->location_id,
                'locationCode' => $r->location_code,
                'qtyOnHand' => (float) $r->qty_on_hand,
            ], $onHand),
            'movements' => array_map(static fn ($r) => [
                'id' => (int) $r->id,
                'movementType' => $r->movement_type,
                'itemId' => $r->item_id,
                'sku' => $r->sku,
                'itemName' => $r->item_name,
                'locationId' => $r->location_id,
                'locationCode' => $r->location_code,
                'qty' => (float) $r->qty,
                'unitCostMinor' => (int) $r->unit_cost_minor,
                'movementDate' => $r->movement_date,
                'sourceType' => $r->source_type,
                'sourceId' => $r->source_id,
                'salesOrderLineId' => $r->sales_order_line_id,
                'goodsReceiptLineId' => $r->goods_receipt_line_id,
                'journalId' => $r->journal_id,
                'memo' => $r->memo,
                'createdAt' => $r->created_at,
            ], $movements),
            'chain' => $chain,
        ];
    }
}
