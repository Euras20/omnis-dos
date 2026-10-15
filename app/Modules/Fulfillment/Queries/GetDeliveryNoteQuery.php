<?php

declare(strict_types=1);

namespace App\Modules\Fulfillment\Queries;

use App\Exceptions\NotFoundException;
use App\Models\DeliveryNote;
use App\Models\DeliveryNoteLine;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;

/**
 * GET /api/v1/delivery-notes/{id} — full delivery note with lines.
 */
final class GetDeliveryNoteQuery
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(string $id): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'stock.view');

        $companyId = $this->companyContext->companyId();

        $note = DeliveryNote::query()
            ->where('company_id', $companyId)
            ->where('id', $id)
            ->with('lines')
            ->first();

        if ($note === null) {
            throw new NotFoundException('Delivery note not found.');
        }

        return [
            'id' => $note->id,
            'deliveryNo' => $note->delivery_no,
            'salesOrderId' => $note->sales_order_id,
            'status' => $note->status,
            'plannedDate' => $note->planned_date?->format('Y-m-d'),
            'revisedDate' => $note->revised_date?->format('Y-m-d'),
            'route' => $note->route,
            'packages' => $note->packages,
            'weightKg' => $note->weight_kg !== null ? (string) $note->weight_kg : null,
            'volumeM3' => $note->volume_m3 !== null ? (string) $note->volume_m3 : null,
            'vehicle' => $note->vehicle,
            'carrier' => $note->carrier,
            'driver' => $note->driver,
            'pickedBy' => $note->picked_by,
            'packedBy' => $note->packed_by,
            'dispatchAt' => $note->dispatch_at?->toIso8601String(),
            'deliveredAt' => $note->delivered_at?->toIso8601String(),
            'attempt' => $note->attempt,
            'receiver' => $note->receiver,
            'podReference' => $note->pod_reference,
            'note' => $note->note,
            'failReason' => $note->fail_reason,
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
                'acceptedQty' => $l->accepted_qty !== null ? (string) $l->accepted_qty : null,
                'refusedQty' => $l->refused_qty !== null ? (string) $l->refused_qty : null,
            ])->all(),
            'createdAt' => $note->created_at?->toIso8601String(),
            'updatedAt' => $note->updated_at?->toIso8601String(),
        ];
    }
}
