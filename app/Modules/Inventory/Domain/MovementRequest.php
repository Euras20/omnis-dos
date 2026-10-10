<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

/**
 * Input for MovementService::record — one stock_movement row.
 * qty must already be signed correctly for the movement type.
 */
final class MovementRequest
{
    public function __construct(
        public readonly string $companyId,
        public readonly string $movementType,
        public readonly string $itemId,
        public readonly string $locationId,
        public readonly ?string $lot,
        public readonly string $qty,
        public readonly int $unitCostMinor,
        public readonly string $movementDate,
        public readonly ?string $sourceType,
        public readonly ?string $sourceId,
        public readonly ?string $salesOrderLineId,
        public readonly ?string $goodsReceiptLineId,
        public readonly ?string $stockAssignmentId,
        public readonly ?string $journalId,
        public readonly ?string $memo,
        public readonly string $actorId,
        public readonly ?string $requestId,
    ) {
    }
}
