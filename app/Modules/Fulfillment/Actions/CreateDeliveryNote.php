<?php

declare(strict_types=1);

namespace App\Modules\Fulfillment\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\NotFoundException;
use App\Models\DeliveryNote;
use App\Models\DeliveryNoteLine;
use App\Models\NumberSequence;
use App\Models\SalesOrder;
use App\Models\StockAssignment;
use App\Modules\Fulfillment\Domain\DeliveryStatus;
use App\Modules\Inventory\Domain\AssignmentStatus;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Outbox\OutboxWriter;
use App\Shared\Permissions\PermissionChecker;
use App\Shared\Support\Uuid;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/delivery-notes — create a planned delivery from assigned stock.
 *
 * Does not issue stock. Stock is issued only when the note is confirmed (POD).
 */
final class CreateDeliveryNote
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
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
        $salesOrderId = (string) ($data['salesOrderId'] ?? '');
        $plannedDate = (string) ($data['plannedDate'] ?? '');
        $assignmentIds = $data['assignmentIds'] ?? [];
        $route = isset($data['route']) ? trim((string) $data['route']) : null;
        $memo = isset($data['memo']) ? trim((string) $data['memo']) : null;
        $requestId = $this->companyContext->requestId();

        if ($salesOrderId === '' || ! preg_match('/^[0-9a-f-]{36}$/i', $salesOrderId)) {
            throw new BusinessRuleException('salesOrderId is required.');
        }
        if ($plannedDate === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $plannedDate)) {
            throw new BusinessRuleException('plannedDate must be YYYY-MM-DD.');
        }
        if (! is_array($assignmentIds) || $assignmentIds === []) {
            throw new BusinessRuleException('assignmentIds must contain at least one assigned stock row.');
        }

        return DB::transaction(function () use (
            $companyId,
            $salesOrderId,
            $plannedDate,
            $assignmentIds,
            $route,
            $memo,
            $actorId,
            $requestId,
        ) {
            $order = SalesOrder::query()
                ->where('company_id', $companyId)
                ->where('id', $salesOrderId)
                ->first();

            if ($order === null) {
                throw new NotFoundException('Sales order not found.');
            }

            $deliveryId = Uuid::v7();
            $deliveryNo = $this->nextNumber($companyId, $plannedDate);

            $note = new DeliveryNote;
            $note->id = $deliveryId;
            $note->company_id = $companyId;
            $note->delivery_no = $deliveryNo;
            $note->sales_order_id = $salesOrderId;
            $note->status = DeliveryStatus::PLANNED;
            $note->planned_date = $plannedDate;
            $note->route = $route;
            $note->note = $memo;
            $note->attempt = 1;
            $note->row_version = 1;
            $note->created_by = $actorId;
            $note->request_id = $requestId;
            $note->save();

            $lineNo = 1;
            foreach ($assignmentIds as $assignmentId) {
                $assignmentId = (string) $assignmentId;
                $assignment = StockAssignment::query()
                    ->where('company_id', $companyId)
                    ->where('id', $assignmentId)
                    ->lockForUpdate()
                    ->first();

                if ($assignment === null) {
                    throw new NotFoundException("Stock assignment {$assignmentId} not found.");
                }
                if ($assignment->status !== AssignmentStatus::ASSIGNED) {
                    throw new BusinessRuleException(
                        "Assignment {$assignmentId} is not assigned (status: {$assignment->status}).",
                    );
                }
                if ($assignment->sales_order_line_id === null) {
                    throw new BusinessRuleException(
                        "Assignment {$assignmentId} is not linked to a sales order line.",
                    );
                }

                // Ensure the assignment belongs to a line of this order
                $line = DB::table('sales_order_line')
                    ->where('id', $assignment->sales_order_line_id)
                    ->where('sales_order_id', $salesOrderId)
                    ->first();
                if ($line === null) {
                    throw new BusinessRuleException(
                        "Assignment {$assignmentId} does not belong to sales order {$salesOrderId}.",
                    );
                }

                // Prevent the same assignment appearing on another open delivery
                $already = DeliveryNoteLine::query()
                    ->where('company_id', $companyId)
                    ->where('stock_assignment_id', $assignmentId)
                    ->whereHas('deliveryNote', function ($q) {
                        $q->whereNotIn('status', [
                            DeliveryStatus::CANCELLED,
                            DeliveryStatus::FAILED,
                        ]);
                    })
                    ->exists();
                if ($already) {
                    throw new BusinessRuleException(
                        "Assignment {$assignmentId} is already on an open delivery note.",
                    );
                }

                $dnl = new DeliveryNoteLine;
                $dnl->id = Uuid::v7();
                $dnl->company_id = $companyId;
                $dnl->delivery_note_id = $deliveryId;
                $dnl->sales_order_line_id = $assignment->sales_order_line_id;
                $dnl->stock_assignment_id = $assignment->id;
                $dnl->item_id = $assignment->item_id;
                $dnl->location_id = $assignment->location_id;
                $dnl->lot = $assignment->lot;
                $dnl->qty = $assignment->qty;
                $dnl->line_no = $lineNo++;
                $dnl->save();
            }

            $this->auditWriter->write(
                action: 'delivery_note.created',
                entityType: 'delivery_note',
                entityId: $deliveryId,
                detail: [
                    'deliveryNo' => $deliveryNo,
                    'salesOrderId' => $salesOrderId,
                    'plannedDate' => $plannedDate,
                    'lineCount' => $lineNo - 1,
                ],
            );

            $this->outboxWriter->record([
                'aggregate_type' => 'delivery_note',
                'aggregate_id' => $deliveryId,
                'event_type' => 'delivery_note.created',
                'payload' => [
                    'deliveryNo' => $deliveryNo,
                    'salesOrderId' => $salesOrderId,
                    'status' => DeliveryStatus::PLANNED,
                ],
            ]);

            $note->load('lines');

            return $this->toArray($note);
        });
    }

    private function nextNumber(string $companyId, string $date): string
    {
        $year = substr($date, 0, 4);
        $seq = NumberSequence::query()
            ->where('company_id', $companyId)
            ->where('doc_type', 'delivery_note')
            ->where('fiscal_year', $year)
            ->lockForUpdate()
            ->first();

        if ($seq === null) {
            $seq = new NumberSequence;
            $seq->id = Uuid::v7();
            $seq->company_id = $companyId;
            $seq->doc_type = 'delivery_note';
            $seq->fiscal_year = $year;
            $seq->next_value = 1;
            $seq->prefix = 'DN';
            $seq->save();
        }

        $value = $seq->next_value;
        $seq->next_value = $value + 1;
        $seq->save();

        return sprintf('DN-%s-%05d', $year, $value);
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(DeliveryNote $note): array
    {
        return [
            'id' => $note->id,
            'deliveryNo' => $note->delivery_no,
            'salesOrderId' => $note->sales_order_id,
            'status' => $note->status,
            'plannedDate' => $note->planned_date?->format('Y-m-d'),
            'route' => $note->route,
            'attempt' => $note->attempt,
            'note' => $note->note,
            'rowVersion' => $note->row_version,
            'lines' => $note->lines->map(static fn (DeliveryNoteLine $l) => [
                'id' => $l->id,
                'lineNo' => $l->line_no,
                'salesOrderLineId' => $l->sales_order_line_id,
                'stockAssignmentId' => $l->stock_assignment_id,
                'itemId' => $l->item_id,
                'locationId' => $l->location_id,
                'lot' => $l->lot,
                'qty' => (string) $l->qty,
            ])->all(),
            'createdAt' => $note->created_at?->toIso8601String(),
        ];
    }
}
