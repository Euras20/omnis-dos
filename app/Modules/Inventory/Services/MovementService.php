<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\NotFoundException;
use App\Models\Item;
use App\Models\Location;
use App\Models\StockMovement;
use App\Modules\Inventory\Domain\MovementRequest;
use App\Modules\Inventory\Domain\MovementType;
use Illuminate\Support\Facades\DB;

/**
 * Sole writer of stock_movement.
 *
 * Other modules (Procurement GRN, Fulfillment delivery, opening, adjustments)
 * call record() inside their own transaction.
 * Does not post journals — callers that need COGS / GRNI post via Ledger\PostingService.
 */
final class MovementService
{
    /**
     * Insert one movement row. Must run inside an open DB transaction.
     *
     * @return int  stock_movement.id
     */
    public function record(MovementRequest $request): int
    {
        if (! MovementType::isKnown($request->movementType)) {
            throw new BusinessRuleException("Unknown movement type '{$request->movementType}'.");
        }

        if ($request->qty === '' || ! is_numeric($request->qty) || (float) $request->qty == 0.0) {
            throw new BusinessRuleException('Movement qty must be a non-zero number.');
        }

        if ($request->unitCostMinor < 0) {
            throw new BusinessRuleException('unit_cost_minor cannot be negative.');
        }

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $request->movementDate)) {
            throw new BusinessRuleException('movementDate must be YYYY-MM-DD.');
        }

        $item = Item::query()
            ->where('company_id', $request->companyId)
            ->where('id', $request->itemId)
            ->where('is_active', true)
            ->first();
        if ($item === null) {
            throw new NotFoundException('Item not found or inactive.');
        }

        $location = Location::query()
            ->where('company_id', $request->companyId)
            ->where('id', $request->locationId)
            ->where('is_active', true)
            ->first();
        if ($location === null) {
            throw new NotFoundException('Location not found or inactive.');
        }

        // For outbound movements, refuse if resulting stock would go negative.
        // Delivery against an assignment only needs on-hand (reservation already holds the qty).
        // Other outbound types need available (on-hand − open assignments).
        if (MovementType::isOutbound($request->movementType) || ((float) $request->qty < 0)) {
            $needed = abs((float) $request->qty);
            if ($request->movementType === MovementType::DELIVERY) {
                $this->assertSufficientOnHand(
                    $request->companyId,
                    $request->itemId,
                    $request->locationId,
                    $request->lot,
                    $needed,
                );
            } else {
                $this->assertSufficientAvailable(
                    $request->companyId,
                    $request->itemId,
                    $request->locationId,
                    $request->lot,
                    $needed,
                );
            }
        }

        $movement = new StockMovement;
        $movement->company_id = $request->companyId;
        $movement->movement_type = $request->movementType;
        $movement->item_id = $request->itemId;
        $movement->location_id = $request->locationId;
        $movement->lot = $request->lot;
        $movement->qty = $request->qty;
        $movement->unit_cost_minor = $request->unitCostMinor;
        $movement->movement_date = $request->movementDate;
        $movement->source_type = $request->sourceType;
        $movement->source_id = $request->sourceId;
        $movement->sales_order_line_id = $request->salesOrderLineId;
        $movement->goods_receipt_line_id = $request->goodsReceiptLineId;
        $movement->stock_assignment_id = $request->stockAssignmentId;
        $movement->journal_id = $request->journalId;
        $movement->memo = $request->memo;
        $movement->created_by = $request->actorId;
        $movement->request_id = $request->requestId;
        $movement->save();

        return (int) $movement->id;
    }

    /**
     * Ensure raw on-hand ≥ requested qty (ignores open assignments).
     */
    public function assertSufficientOnHand(
        string $companyId,
        string $itemId,
        string $locationId,
        ?string $lot,
        float $neededQty,
    ): void {
        $row = DB::selectOne(
            <<<'SQL'
                SELECT COALESCE(SUM(qty), 0) AS qty_on_hand
                FROM stock_movement
                WHERE company_id = ? AND item_id = ? AND location_id = ?
                  AND (lot IS NOT DISTINCT FROM ?)
            SQL,
            [$companyId, $itemId, $locationId, $lot],
        );

        $onHand = (float) ($row->qty_on_hand ?? 0);
        if ($onHand + 1e-9 < $neededQty) {
            throw new BusinessRuleException(
                sprintf(
                    'Insufficient on-hand stock: need %.3f, on hand %.3f.',
                    $neededQty,
                    $onHand,
                ),
            );
        }
    }

    /**
     * Ensure on-hand − open assignments ≥ requested qty for item/location/lot.
     */
    public function assertSufficientAvailable(
        string $companyId,
        string $itemId,
        string $locationId,
        ?string $lot,
        float $neededQty,
    ): void {
        $row = DB::selectOne(
            <<<'SQL'
                SELECT
                    COALESCE(oh.qty_on_hand, 0) AS qty_on_hand,
                    COALESCE(a.qty_assigned, 0) AS qty_assigned
                FROM (SELECT 1) AS dummy
                LEFT JOIN (
                    SELECT company_id, item_id, location_id, lot, SUM(qty) AS qty_on_hand
                    FROM stock_movement
                    WHERE company_id = ? AND item_id = ? AND location_id = ?
                      AND (lot IS NOT DISTINCT FROM ?)
                    GROUP BY company_id, item_id, location_id, lot
                ) oh ON true
                LEFT JOIN (
                    SELECT company_id, item_id, location_id, lot, SUM(qty) AS qty_assigned
                    FROM stock_assignment
                    WHERE company_id = ? AND item_id = ? AND location_id = ?
                      AND (lot IS NOT DISTINCT FROM ?)
                      AND status IN ('pending', 'assigned')
                    GROUP BY company_id, item_id, location_id, lot
                ) a ON true
            SQL,
            [$companyId, $itemId, $locationId, $lot, $companyId, $itemId, $locationId, $lot],
        );

        $onHand = (float) ($row->qty_on_hand ?? 0);
        $assigned = (float) ($row->qty_assigned ?? 0);
        $available = $onHand - $assigned;

        if ($available + 1e-9 < $neededQty) {
            throw new BusinessRuleException(
                sprintf(
                    'Insufficient available stock: need %.3f, available %.3f (on hand %.3f, assigned %.3f).',
                    $neededQty,
                    $available,
                    $onHand,
                    $assigned,
                ),
            );
        }
    }

    /**
     * Weighted average unit cost (minor) for a lot at a location, or 0 if none.
     */
    public function averageUnitCostMinor(
        string $companyId,
        string $itemId,
        string $locationId,
        ?string $lot,
    ): int {
        $row = DB::selectOne(
            <<<'SQL'
                SELECT
                    CASE
                        WHEN SUM(qty) = 0 THEN 0
                        ELSE ROUND(SUM(qty * unit_cost_minor) / NULLIF(SUM(qty), 0))
                    END AS avg_cost
                FROM stock_movement
                WHERE company_id = ? AND item_id = ? AND location_id = ?
                  AND (lot IS NOT DISTINCT FROM ?)
            SQL,
            [$companyId, $itemId, $locationId, $lot],
        );

        return (int) ($row->avg_cost ?? 0);
    }
}
