<?php

declare(strict_types=1);

namespace App\Modules\Logistics\Queries;

use App\Exceptions\NotFoundException;
use App\Models\Consignment;
use App\Models\CustomsDeclaration;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\ShipmentLeg;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;

/** GET /api/v1/shipments/{id} — detail with consignments, legs, events, customs. */
final class GetShipmentQuery
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
        $this->permissionChecker->assertCan($actorId, 'logistics.view');

        $companyId = $this->companyContext->companyId();

        $s = Shipment::query()
            ->where('company_id', $companyId)
            ->where('id', $id)
            ->with(['consignments.legs', 'purchaseOrders', 'events', 'customs'])
            ->first();

        if ($s === null) {
            throw new NotFoundException('Shipment not found.');
        }

        return [
            'id' => $s->id,
            'shipmentNo' => $s->shipment_no,
            'kind' => $s->kind,
            'transport' => $s->transport,
            'mode' => $s->mode,
            'booking' => $s->booking,
            'incoterm' => $s->incoterm,
            'shipperId' => $s->shipper_id,
            'consignee' => $s->consignee,
            'carrierId' => $s->carrier_id,
            'forwarderId' => $s->forwarder_id,
            'insurance' => $s->insurance,
            'status' => $s->status,
            'owner' => $s->owner,
            'note' => $s->note,
            'purchaseOrderIds' => $s->purchaseOrders->pluck('purchase_order_id')->all(),
            'consignments' => $s->consignments->map(static fn (Consignment $c) => [
                'id' => $c->id,
                'consignmentNo' => $c->consignment_no,
                'purchaseOrderLineId' => $c->purchase_order_line_id,
                'itemId' => $c->item_id,
                'qty' => (string) $c->qty,
                'container' => $c->container,
                'seal' => $c->seal,
                'bl' => $c->bl,
                'packages' => $c->packages,
                'weightKg' => $c->weight_kg !== null ? (string) $c->weight_kg : null,
                'volumeM3' => $c->volume_m3 !== null ? (string) $c->volume_m3 : null,
                'status' => $c->status,
                'eta' => $c->eta?->format('Y-m-d'),
                'goodsReceiptId' => $c->goods_receipt_id,
                'legs' => $c->legs->map(static fn (ShipmentLeg $l) => [
                    'id' => $l->id,
                    'seq' => $l->seq,
                    'from' => $l->from_location,
                    'to' => $l->to_location,
                    'carrierId' => $l->carrier_id,
                    'vessel' => $l->vessel,
                    'plannedDep' => $l->planned_dep?->format('Y-m-d'),
                    'plannedArr' => $l->planned_arr?->format('Y-m-d'),
                    'actualDep' => $l->actual_dep?->format('Y-m-d'),
                    'actualArr' => $l->actual_arr?->format('Y-m-d'),
                ])->all(),
            ])->all(),
            'events' => $s->events->sortByDesc('event_at')->values()->map(static fn (ShipmentEvent $e) => [
                'id' => $e->id,
                'eventAt' => $e->event_at?->toIso8601String(),
                'eventType' => $e->event_type,
                'location' => $e->location,
                'consignmentId' => $e->consignment_id,
                'qtyAffected' => $e->qty_affected !== null ? (string) $e->qty_affected : null,
                'source' => $e->source,
            ])->all(),
            'customs' => $s->customs->map(static fn (CustomsDeclaration $d) => [
                'id' => $d->id,
                'declarationNo' => $d->declaration_no,
                'status' => $d->status,
                'freeTimeEnd' => $d->free_time_end?->format('Y-m-d'),
            ])->all(),
            'rowVersion' => $s->row_version,
            'createdAt' => $s->created_at?->toIso8601String(),
        ];
    }
}
