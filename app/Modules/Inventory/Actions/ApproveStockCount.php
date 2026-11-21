<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\NotFoundException;
use App\Exceptions\SodViolationException;
use App\Models\StockCount;
use App\Modules\Inventory\Domain\MovementRequest;
use App\Modules\Inventory\Domain\MovementType;
use App\Modules\Inventory\Services\MovementService;
use App\Modules\Ledger\Domain\PostingKey;
use App\Modules\Ledger\Domain\PostingLine;
use App\Modules\Ledger\Domain\PostingRequest;
use App\Modules\Ledger\Services\PostingService;
use App\Modules\Ledger\Services\RoleResolver;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/stock/counts/{id}/approve — supervisor posts variance adjustments.
 * SoD: approver must differ from counted_by when both are set.
 */
final class ApproveStockCount
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly MovementService $movementService,
        private readonly PostingService $postingService,
        private readonly RoleResolver $roleResolver,
        private readonly AuditWriter $auditWriter,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function execute(string $id, array $data = []): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'stock.approve');
        $requestId = $this->companyContext->requestId();
        $reject = (bool) ($data['reject'] ?? false);

        return DB::transaction(function () use ($id, $actorId, $requestId, $reject) {
            $count = StockCount::query()->with('lines')->where('id', $id)->lockForUpdate()->first();
            if ($count === null) {
                throw new NotFoundException('Stock count not found.');
            }
            if ($count->status !== 'awaiting_supervisor') {
                throw new BusinessRuleException('Only counts awaiting supervisor can be approved or rejected.');
            }
            if (
                $count->counted_by !== null
                && $count->counted_by === $actorId
                && ! $this->permissionChecker->isSystemAdministrator($actorId)
            ) {
                throw new SodViolationException(
                    'The stock keeper who submitted the count cannot approve it (segregation of duties).'
                );
            }

            if ($reject) {
                $count->status = 'rejected';
                $count->approved_by = $actorId;
                $count->approved_at = now();
                $count->save();

                $this->auditWriter->write(
                    action: 'stock_count.rejected',
                    entityType: 'stock_count',
                    entityId: $count->id,
                    detail: ['countNo' => $count->count_no],
                    actorId: $actorId,
                );

                return CreateStockCount::toArray(
                    StockCount::query()->with(['lines.item', 'location'])->findOrFail($count->id)
                );
            }

            $companyId = (string) $count->company_id;
            $date = $count->count_date->format('Y-m-d');

            foreach ($count->lines as $line) {
                $variance = (float) $line->counted_qty - (float) $line->book_qty;
                if (abs($variance) < 0.0005) {
                    continue;
                }
                if ($variance < 0 && ($line->reason === null || trim((string) $line->reason) === '')) {
                    throw new BusinessRuleException(
                        "Line {$line->line_no}: variance write-off requires a reason."
                    );
                }

                $unitCost = $this->movementService->averageUnitCostMinor(
                    $companyId,
                    (string) $line->item_id,
                    (string) $count->location_id,
                    $line->lot,
                );

                $movementId = $this->movementService->record(new MovementRequest(
                    companyId: $companyId,
                    movementType: MovementType::ADJUSTMENT,
                    itemId: (string) $line->item_id,
                    locationId: (string) $count->location_id,
                    lot: $line->lot,
                    qty: number_format($variance, 3, '.', ''),
                    unitCostMinor: $unitCost,
                    movementDate: $date,
                    sourceType: 'stock_count',
                    sourceId: (string) $count->id,
                    salesOrderLineId: null,
                    goodsReceiptLineId: null,
                    stockAssignmentId: null,
                    journalId: null,
                    memo: $line->reason ?? "Stock count {$count->count_no}",
                    actorId: $actorId,
                    requestId: $requestId,
                ));

                $line->adjustment_movement_id = $movementId;
                $line->save();
            }

            $value = 0;
            foreach ($count->lines as $line) {
                $variance = (float) $line->counted_qty - (float) $line->book_qty;
                if (abs($variance) < 0.0005) {
                    continue;
                }
                $unitCost = $this->movementService->averageUnitCostMinor(
                    $companyId,
                    (string) $line->item_id,
                    (string) $count->location_id,
                    $line->lot,
                );
                $value += (int) round($variance * $unitCost);
            }
            if ($value !== 0) {
                $invId = $this->roleResolver->id($companyId, 'INVENTORY');
                $adjId = $this->roleResolver->id($companyId, 'STOCK_ADJUSTMENT');
                $debit = $value > 0 ? $value : 0;
                $credit = $value < 0 ? abs($value) : 0;
                $this->postingService->post(new PostingRequest(
                    companyId: $companyId,
                    postingDate: $date,
                    postingKey: PostingKey::make('stock_count', $count->id, 'post'),
                    sourceType: 'stock_count',
                    sourceId: $count->id,
                    eventType: 'stock_count_post',
                    description: "Stock count {$count->count_no}",
                    lines: [
                        new PostingLine(accountId: $invId, debitMinor: $debit, creditMinor: $credit),
                        new PostingLine(accountId: $adjId, debitMinor: $credit, creditMinor: $debit),
                    ],
                    actorId: $actorId,
                    requestId: $requestId,
                    currency: 'GHS',
                ));
            }

            $count->status = 'posted';
            $count->approved_by = $actorId;
            $count->approved_at = now();
            $count->save();

            $this->auditWriter->write(
                action: 'stock_count.approved',
                entityType: 'stock_count',
                entityId: $count->id,
                detail: ['countNo' => $count->count_no],
                actorId: $actorId,
            );

            return CreateStockCount::toArray(
                StockCount::query()->with(['lines.item', 'location', 'countedByUser', 'approvedByUser'])->findOrFail($count->id)
            );
        });
    }
}
