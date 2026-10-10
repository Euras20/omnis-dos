<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\NotFoundException;
use App\Models\Account;
use App\Models\StockAssignment;
use App\Modules\Inventory\Domain\AssignmentStatus;
use App\Modules\Inventory\Domain\MovementRequest;
use App\Modules\Inventory\Domain\MovementType;
use App\Modules\Inventory\Services\MovementService;
use App\Modules\Ledger\Domain\PostingKey;
use App\Modules\Ledger\Domain\PostingLine;
use App\Modules\Ledger\Domain\PostingRequest;
use App\Modules\Ledger\Services\PostingService;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Outbox\OutboxWriter;
use App\Shared\Permissions\PermissionChecker;
use App\Shared\Support\Uuid;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/deliveries — issue assigned stock (COGS Dr, Inventory Cr at lot cost).
 *
 * Requires a confirmed (assigned) stock_assignment. Marks it delivered,
 * records a delivery movement, and posts one balanced journal.
 */
final class PostDelivery
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly MovementService $movementService,
        private readonly PostingService $postingService,
        private readonly AuditWriter $auditWriter,
        private readonly OutboxWriter $outboxWriter,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function execute(array $data): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'stock.move');

        $companyId = $this->companyContext->companyId();
        $assignmentId = (string) ($data['assignmentId'] ?? '');
        $deliveryDate = (string) ($data['deliveryDate'] ?? $data['date'] ?? '');
        $memo = isset($data['memo']) ? trim((string) $data['memo']) : null;
        $requestId = $this->companyContext->requestId();

        if ($assignmentId === '') {
            throw new BusinessRuleException('assignmentId is required.');
        }
        if ($deliveryDate === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $deliveryDate)) {
            throw new BusinessRuleException('deliveryDate must be YYYY-MM-DD.');
        }

        return DB::transaction(function () use (
            $companyId,
            $assignmentId,
            $deliveryDate,
            $memo,
            $actorId,
            $requestId,
        ) {
            $assignment = StockAssignment::query()
                ->where('company_id', $companyId)
                ->where('id', $assignmentId)
                ->lockForUpdate()
                ->first();

            if ($assignment === null) {
                throw new NotFoundException('Stock assignment not found.');
            }

            if ($assignment->status !== AssignmentStatus::ASSIGNED) {
                throw new BusinessRuleException(
                    "Only assigned stock can be delivered (current status: {$assignment->status}).",
                );
            }

            $unitCost = $this->movementService->averageUnitCostMinor(
                $companyId,
                $assignment->item_id,
                $assignment->location_id,
                $assignment->lot,
            );

            $qty = (float) $assignment->qty;
            $costTotal = (int) round($qty * $unitCost);

            // Resolve inventory and COGS accounts by code convention (1300 inventory, 5000 COGS).
            $inventoryAccount = $this->accountByCode($companyId, '1300');
            $cogsAccount = $this->accountByCode($companyId, '5000');

            $deliveryId = Uuid::v7();
            $postingKey = PostingKey::make('delivery', $deliveryId, 'deliver');

            $journalId = $this->postingService->post(new PostingRequest(
                companyId: $companyId,
                postingDate: $deliveryDate,
                postingKey: $postingKey,
                sourceType: 'delivery',
                sourceId: $deliveryId,
                eventType: 'deliver',
                description: $memo ?? "Delivery {$deliveryId}",
                lines: [
                    new PostingLine(
                        accountId: $cogsAccount->id,
                        debitMinor: $costTotal,
                        creditMinor: 0,
                        partyId: null,
                        memo: 'Cost of sales',
                    ),
                    new PostingLine(
                        accountId: $inventoryAccount->id,
                        debitMinor: 0,
                        creditMinor: $costTotal,
                        partyId: null,
                        memo: 'Inventory issue',
                    ),
                ],
                actorId: $actorId,
                requestId: $requestId,
                preparedBy: $actorId,
            ));

            // Issue movement (negative qty)
            $movementId = $this->movementService->record(new MovementRequest(
                companyId: $companyId,
                movementType: MovementType::DELIVERY,
                itemId: $assignment->item_id,
                locationId: $assignment->location_id,
                lot: $assignment->lot,
                qty: number_format(-$qty, 3, '.', ''),
                unitCostMinor: $unitCost,
                movementDate: $deliveryDate,
                sourceType: 'delivery',
                sourceId: $deliveryId,
                salesOrderLineId: $assignment->sales_order_line_id,
                goodsReceiptLineId: null,
                stockAssignmentId: $assignment->id,
                journalId: $journalId,
                memo: $memo,
                actorId: $actorId,
                requestId: $requestId,
            ));

            $assignment->status = AssignmentStatus::DELIVERED;
            $assignment->save();

            $this->auditWriter->write(
                action: 'stock.delivery.post',
                entityType: 'delivery',
                entityId: $deliveryId,
                detail: [
                    'assignmentId' => $assignment->id,
                    'itemId' => $assignment->item_id,
                    'locationId' => $assignment->location_id,
                    'lot' => $assignment->lot,
                    'qty' => (string) $assignment->qty,
                    'unitCostMinor' => $unitCost,
                    'costTotalMinor' => $costTotal,
                    'journalId' => $journalId,
                    'movementId' => $movementId,
                ],
            );

            $this->outboxWriter->record([
                'aggregate_type' => 'delivery',
                'aggregate_id' => $deliveryId,
                'event_type' => 'delivery.posted',
                'payload' => [
                    'deliveryId' => $deliveryId,
                    'assignmentId' => $assignment->id,
                    'journalId' => $journalId,
                ],
            ]);

            return [
                'id' => $deliveryId,
                'assignmentId' => $assignment->id,
                'itemId' => $assignment->item_id,
                'locationId' => $assignment->location_id,
                'lot' => $assignment->lot,
                'qty' => (string) $assignment->qty,
                'unitCostMinor' => $unitCost,
                'costTotalMinor' => $costTotal,
                'deliveryDate' => $deliveryDate,
                'journalId' => $journalId,
                'movementId' => $movementId,
                'status' => AssignmentStatus::DELIVERED,
            ];
        });
    }

    private function accountByCode(string $companyId, string $code): Account
    {
        $account = Account::query()
            ->where('company_id', $companyId)
            ->where('code', $code)
            ->where('is_active', true)
            ->first();

        if ($account === null) {
            throw new BusinessRuleException("Required account code {$code} is missing or inactive.");
        }

        return $account;
    }
}
