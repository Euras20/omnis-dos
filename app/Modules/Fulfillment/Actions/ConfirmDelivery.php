<?php

declare(strict_types=1);

namespace App\Modules\Fulfillment\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\NotFoundException;
use App\Models\Account;
use App\Models\DeliveryNote;
use App\Models\DeliveryNoteLine;
use App\Models\SalesOrderLine;
use App\Models\StockAssignment;
use App\Modules\Fulfillment\Domain\DeliveryStatus;
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
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/delivery-notes/{id}/confirm — confirm delivery + POD.
 *
 * dispatched → delivered. Issues stock (COGS Dr, Inventory Cr) and
 * marks assignments delivered. One journal for the whole note.
 */
final class ConfirmDelivery
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
    public function execute(string $id, array $data): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'stock.move');

        $companyId = $this->companyContext->companyId();
        $receiver = isset($data['receiver']) ? trim((string) $data['receiver']) : null;
        $podReference = isset($data['podReference']) ? trim((string) $data['podReference']) : null;
        $noteText = isset($data['note']) ? trim((string) $data['note']) : null;
        $deliveredDate = (string) ($data['deliveredDate'] ?? date('Y-m-d'));
        $requestId = $this->companyContext->requestId();

        if ($deliveredDate === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $deliveredDate)) {
            throw new BusinessRuleException('deliveredDate must be YYYY-MM-DD.');
        }

        return DB::transaction(function () use (
            $companyId,
            $id,
            $receiver,
            $podReference,
            $noteText,
            $deliveredDate,
            $actorId,
            $requestId,
        ) {
            $note = DeliveryNote::query()
                ->where('company_id', $companyId)
                ->where('id', $id)
                ->lockForUpdate()
                ->first();

            if ($note === null) {
                throw new NotFoundException('Delivery note not found.');
            }
            if ($note->status !== DeliveryStatus::DISPATCHED) {
                throw new BusinessRuleException(
                    "Only dispatched deliveries can be confirmed (current: {$note->status}).",
                );
            }

            $lines = DeliveryNoteLine::query()
                ->where('company_id', $companyId)
                ->where('delivery_note_id', $note->id)
                ->get();

            if ($lines->isEmpty()) {
                throw new BusinessRuleException('Delivery note has no lines.');
            }

            $inventoryAccount = $this->accountByCode($companyId, '1300');
            $cogsAccount = $this->accountByCode($companyId, '5000');

            $postingLines = [];
            $totalCost = 0;
            $movementIds = [];

            foreach ($lines as $line) {
                $assignment = StockAssignment::query()
                    ->where('company_id', $companyId)
                    ->where('id', $line->stock_assignment_id)
                    ->lockForUpdate()
                    ->first();

                if ($assignment === null) {
                    throw new NotFoundException("Assignment {$line->stock_assignment_id} not found.");
                }
                if ($assignment->status !== AssignmentStatus::ASSIGNED) {
                    throw new BusinessRuleException(
                        "Assignment {$assignment->id} is no longer assigned (status: {$assignment->status}).",
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
                $totalCost += $costTotal;

                $movementId = $this->movementService->record(new MovementRequest(
                    companyId: $companyId,
                    movementType: MovementType::DELIVERY,
                    itemId: $assignment->item_id,
                    locationId: $assignment->location_id,
                    lot: $assignment->lot,
                    qty: number_format(-$qty, 3, '.', ''),
                    unitCostMinor: $unitCost,
                    movementDate: $deliveredDate,
                    sourceType: 'delivery_note',
                    sourceId: $note->id,
                    salesOrderLineId: $assignment->sales_order_line_id,
                    goodsReceiptLineId: null,
                    stockAssignmentId: $assignment->id,
                    journalId: null, // set after journal is posted; movement records source
                    memo: $noteText ?? "Delivery {$note->delivery_no}",
                    actorId: $actorId,
                    requestId: $requestId,
                ));
                $movementIds[] = $movementId;

                $assignment->status = AssignmentStatus::DELIVERED;
                $assignment->save();

                $line->accepted_qty = $assignment->qty;
                $line->refused_qty = 0;
                $line->save();

                if ($line->sales_order_line_id !== null) {
                    $sol = SalesOrderLine::query()
                        ->where('company_id', $companyId)
                        ->where('id', $line->sales_order_line_id)
                        ->lockForUpdate()
                        ->first();
                    if ($sol !== null) {
                        $sol->qty_delivered = (float) $sol->qty_delivered + $qty;
                        $sol->save();
                    }
                }

                $postingLines[] = new PostingLine(
                    accountId: $cogsAccount->id,
                    debitMinor: $costTotal,
                    creditMinor: 0,
                    partyId: null,
                    memo: "COGS {$note->delivery_no} line {$line->line_no}",
                );
                $postingLines[] = new PostingLine(
                    accountId: $inventoryAccount->id,
                    debitMinor: 0,
                    creditMinor: $costTotal,
                    partyId: null,
                    memo: "Inventory issue {$note->delivery_no} line {$line->line_no}",
                );
            }

            $postingKey = PostingKey::make('delivery_note', $note->id, 'deliver');
            $journalId = $this->postingService->post(new PostingRequest(
                companyId: $companyId,
                postingDate: $deliveredDate,
                postingKey: $postingKey,
                sourceType: 'delivery_note',
                sourceId: $note->id,
                eventType: 'deliver',
                description: $noteText ?? "Delivery {$note->delivery_no}",
                lines: $postingLines,
                actorId: $actorId,
                requestId: $requestId,
                preparedBy: $actorId,
            ));

            $note->status = DeliveryStatus::DELIVERED;
            $note->delivered_at = now();
            $note->receiver = $receiver;
            $note->pod_reference = $podReference;
            if ($noteText !== null && $noteText !== '') {
                $note->note = $noteText;
            }
            $note->save();

            $this->auditWriter->write(
                action: 'delivery_note.confirmed',
                entityType: 'delivery_note',
                entityId: $note->id,
                detail: [
                    'deliveryNo' => $note->delivery_no,
                    'receiver' => $receiver,
                    'podReference' => $podReference,
                    'costTotalMinor' => $totalCost,
                    'journalId' => $journalId,
                    'movementIds' => $movementIds,
                    'lineCount' => $lines->count(),
                ],
            );

            $this->outboxWriter->record([
                'aggregate_type' => 'delivery_note',
                'aggregate_id' => $note->id,
                'event_type' => 'delivery_note.confirmed',
                'payload' => [
                    'deliveryNo' => $note->delivery_no,
                    'status' => DeliveryStatus::DELIVERED,
                    'journalId' => $journalId,
                    'costTotalMinor' => $totalCost,
                ],
            ]);

            return [
                'id' => $note->id,
                'deliveryNo' => $note->delivery_no,
                'status' => $note->status,
                'deliveredAt' => $note->delivered_at?->toIso8601String(),
                'receiver' => $note->receiver,
                'podReference' => $note->pod_reference,
                'costTotalMinor' => $totalCost,
                'journalId' => $journalId,
                'movementIds' => $movementIds,
                'rowVersion' => $note->row_version,
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
