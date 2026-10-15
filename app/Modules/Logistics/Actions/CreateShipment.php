<?php

declare(strict_types=1);

namespace App\Modules\Logistics\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\NotFoundException;
use App\Models\Consignment;
use App\Models\NumberSequence;
use App\Models\PurchaseOrderLine;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\ShipmentLeg;
use App\Models\ShipmentPurchaseOrder;
use App\Modules\Logistics\Domain\ConsignmentStatus;
use App\Modules\Logistics\Domain\ShipmentStatus;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Outbox\OutboxWriter;
use App\Shared\Permissions\PermissionChecker;
use App\Shared\Support\Uuid;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/shipments — create a shipment that can group lines from many POs.
 *
 * Bulk: each consignment references a purchase_order_line. The distinct PO ids
 * are written to shipment_purchase_order so one container can carry many orders.
 */
final class CreateShipment
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
        $this->permissionChecker->assertCan($actorId, 'logistics.manage');

        $companyId = $this->companyContext->companyId();
        $requestId = $this->companyContext->requestId();

        $consignments = $data['consignments'] ?? [];
        if (! is_array($consignments) || $consignments === []) {
            throw new BusinessRuleException('At least one consignment is required.');
        }

        return DB::transaction(function () use ($data, $consignments, $companyId, $actorId, $requestId) {
            $shipmentId = Uuid::v7();
            $shipmentNo = $this->nextNumber($companyId, 'shipment', 'SH', date('Y'));

            $shipment = new Shipment;
            $shipment->id = $shipmentId;
            $shipment->company_id = $companyId;
            $shipment->shipment_no = $shipmentNo;
            $shipment->kind = (string) ($data['kind'] ?? 'Import');
            $shipment->transport = (string) ($data['transport'] ?? 'Sea');
            $shipment->mode = isset($data['mode']) ? trim((string) $data['mode']) : null;
            $shipment->booking = isset($data['booking']) ? trim((string) $data['booking']) : null;
            $shipment->incoterm = isset($data['incoterm']) ? trim((string) $data['incoterm']) : null;
            $shipment->shipper_id = $data['shipperId'] ?? null;
            $shipment->consignee = isset($data['consignee']) ? trim((string) $data['consignee']) : 'Company';
            $shipment->carrier_id = $data['carrierId'] ?? null;
            $shipment->forwarder_id = $data['forwarderId'] ?? null;
            $shipment->insurance = isset($data['insurance']) ? trim((string) $data['insurance']) : null;
            $shipment->status = ShipmentStatus::BOOKED;
            $shipment->owner = isset($data['owner']) ? trim((string) $data['owner']) : null;
            $shipment->note = isset($data['note']) ? trim((string) $data['note']) : null;
            $shipment->row_version = 1;
            $shipment->created_by = $actorId;
            $shipment->request_id = $requestId;
            $shipment->save();

            $poIds = [];
            $cnIndex = 1;

            foreach ($consignments as $cn) {
                $lineId = (string) ($cn['purchaseOrderLineId'] ?? '');
                $line = null;
                $itemId = $cn['itemId'] ?? null;

                if ($lineId !== '') {
                    $line = PurchaseOrderLine::query()
                        ->where('company_id', $companyId)
                        ->where('id', $lineId)
                        ->first();

                    if ($line === null) {
                        throw new NotFoundException("Purchase order line {$lineId} not found.");
                    }

                    $poIds[$line->purchase_order_id] = true;
                    $itemId = $itemId ?: $line->item_id;
                }

                $qty = (string) ($cn['qty'] ?? '0');
                if ((float) $qty <= 0) {
                    throw new BusinessRuleException('Consignment quantity must be greater than zero.');
                }

                $cnId = Uuid::v7();
                $cnNo = sprintf('CN-%s-%d', substr($shipmentNo, -4), $cnIndex);

                $consignment = new Consignment;
                $consignment->id = $cnId;
                $consignment->company_id = $companyId;
                $consignment->shipment_id = $shipmentId;
                $consignment->consignment_no = $cnNo;
                $consignment->purchase_order_line_id = $line?->id;
                $consignment->item_id = $itemId;
                $consignment->qty = $qty;
                $consignment->container = isset($cn['container']) ? trim((string) $cn['container']) : null;
                $consignment->seal = isset($cn['seal']) ? trim((string) $cn['seal']) : null;
                $consignment->bl = isset($cn['bl']) ? trim((string) $cn['bl']) : null;
                $consignment->packages = (int) ($cn['packages'] ?? 0);
                $consignment->weight_kg = isset($cn['weightKg']) ? (string) $cn['weightKg'] : null;
                $consignment->volume_m3 = isset($cn['volumeM3']) ? (string) $cn['volumeM3'] : null;
                $consignment->status = ConsignmentStatus::BOOKED;
                $consignment->eta = $cn['eta'] ?? null;
                $consignment->row_version = 1;
                $consignment->save();

                $legs = $cn['legs'] ?? [];
                if (is_array($legs)) {
                    foreach ($legs as $i => $leg) {
                        $legModel = new ShipmentLeg;
                        $legModel->id = Uuid::v7();
                        $legModel->company_id = $companyId;
                        $legModel->consignment_id = $cnId;
                        $legModel->seq = (int) ($leg['seq'] ?? ($i + 1));
                        $legModel->from_location = (string) ($leg['from'] ?? '');
                        $legModel->to_location = (string) ($leg['to'] ?? '');
                        $legModel->carrier_id = $leg['carrierId'] ?? null;
                        $legModel->vessel = isset($leg['vessel']) ? trim((string) $leg['vessel']) : null;
                        $legModel->planned_dep = $leg['plannedDep'] ?? null;
                        $legModel->planned_arr = $leg['plannedArr'] ?? null;
                        $legModel->save();
                    }
                }

                $cnIndex++;
            }

            foreach (array_keys($poIds) as $poId) {
                $pivot = new ShipmentPurchaseOrder;
                $pivot->id = Uuid::v7();
                $pivot->company_id = $companyId;
                $pivot->shipment_id = $shipmentId;
                $pivot->purchase_order_id = $poId;
                $pivot->save();
            }

            $event = new ShipmentEvent;
            $event->id = Uuid::v7();
            $event->company_id = $companyId;
            $event->shipment_id = $shipmentId;
            $event->event_at = now();
            $event->event_type = 'Booked';
            $event->location = $data['origin'] ?? null;
            $event->source = 'Manual';
            $event->created_by = $actorId;
            $event->save();

            $this->auditWriter->write(
                'shipment.created',
                'shipment',
                $shipmentId,
                [
                    'shipmentNo' => $shipmentNo,
                    'poCount' => count($poIds),
                    'consignmentCount' => $cnIndex - 1,
                ],
            );

            $this->outboxWriter->record([
                'aggregate_type' => 'shipment',
                'aggregate_id' => $shipmentId,
                'event_type' => 'shipment.created',
                'payload' => [
                    'shipmentNo' => $shipmentNo,
                    'status' => ShipmentStatus::BOOKED,
                    'poIds' => array_keys($poIds),
                ],
            ]);

            $shipment->load(['consignments.legs', 'purchaseOrders']);

            return $this->toArray($shipment);
        });
    }

    private function nextNumber(string $companyId, string $docType, string $prefix, string $year): string
    {
        $seq = NumberSequence::query()
            ->where('company_id', $companyId)
            ->where('doc_type', $docType)
            ->where('fiscal_year', $year)
            ->lockForUpdate()
            ->first();

        if ($seq === null) {
            $seq = new NumberSequence;
            $seq->id = Uuid::v7();
            $seq->company_id = $companyId;
            $seq->doc_type = $docType;
            $seq->fiscal_year = $year;
            $seq->next_value = 1;
            $seq->prefix = $prefix;
            $seq->save();
        }

        $value = $seq->next_value;
        $seq->next_value = $value + 1;
        $seq->save();

        return sprintf('%s-%s-%05d', $prefix, $year, $value);
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(Shipment $s): array
    {
        return [
            'id' => $s->id,
            'shipmentNo' => $s->shipment_no,
            'kind' => $s->kind,
            'transport' => $s->transport,
            'mode' => $s->mode,
            'booking' => $s->booking,
            'incoterm' => $s->incoterm,
            'status' => $s->status,
            'purchaseOrderIds' => $s->purchaseOrders->pluck('purchase_order_id')->all(),
            'consignments' => $s->consignments->map(static function (Consignment $c) {
                return [
                    'id' => $c->id,
                    'consignmentNo' => $c->consignment_no,
                    'purchaseOrderLineId' => $c->purchase_order_line_id,
                    'itemId' => $c->item_id,
                    'qty' => (string) $c->qty,
                    'container' => $c->container,
                    'bl' => $c->bl,
                    'status' => $c->status,
                    'eta' => $c->eta?->format('Y-m-d'),
                    'legs' => $c->legs->map(static fn (ShipmentLeg $l) => [
                        'seq' => $l->seq,
                        'from' => $l->from_location,
                        'to' => $l->to_location,
                        'vessel' => $l->vessel,
                        'plannedDep' => $l->planned_dep?->format('Y-m-d'),
                        'plannedArr' => $l->planned_arr?->format('Y-m-d'),
                    ])->all(),
                ];
            })->all(),
            'createdAt' => $s->created_at?->toIso8601String(),
        ];
    }
}
