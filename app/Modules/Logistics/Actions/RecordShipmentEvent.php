<?php

declare(strict_types=1);

namespace App\Modules\Logistics\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\NotFoundException;
use App\Models\Consignment;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Modules\Logistics\Domain\CargoEventType;
use App\Modules\Logistics\Domain\ConsignmentStatus;
use App\Modules\Logistics\Domain\ShipmentStatus;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Outbox\OutboxWriter;
use App\Shared\Permissions\PermissionChecker;
use App\Shared\Support\Uuid;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/shipments/{id}/events — record a cargo event.
 *
 * Updates consignment (and shipment) status for the key event types the
 * frontend uses: Departed, Port arrived, Customs hold, Booking rolled.
 */
final class RecordShipmentEvent
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
    public function execute(string $shipmentId, array $data): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'logistics.manage');

        $companyId = $this->companyContext->companyId();
        $type = (string) ($data['eventType'] ?? '');
        $location = trim((string) ($data['location'] ?? ''));

        if (! in_array($type, CargoEventType::all(), true)) {
            throw new BusinessRuleException('Unknown event type.');
        }
        if ($location === '') {
            throw new BusinessRuleException('Location is required.');
        }

        return DB::transaction(function () use ($shipmentId, $data, $type, $location, $companyId, $actorId) {
            $shipment = Shipment::query()
                ->where('company_id', $companyId)
                ->where('id', $shipmentId)
                ->lockForUpdate()
                ->first();

            if ($shipment === null) {
                throw new NotFoundException('Shipment not found.');
            }

            $consignmentId = $data['consignmentId'] ?? null;
            $consignment = null;

            if ($consignmentId !== null) {
                $consignment = Consignment::query()
                    ->where('company_id', $companyId)
                    ->where('id', $consignmentId)
                    ->where('shipment_id', $shipmentId)
                    ->lockForUpdate()
                    ->first();

                if ($consignment === null) {
                    throw new NotFoundException('Consignment not found on this shipment.');
                }
            }

            $event = new ShipmentEvent;
            $event->id = Uuid::v7();
            $event->company_id = $companyId;
            $event->shipment_id = $shipmentId;
            $event->consignment_id = $consignment?->id;
            $event->event_at = now();
            $event->event_type = $type;
            $event->location = $location;
            $event->qty_affected = isset($data['qtyAffected']) ? (string) $data['qtyAffected'] : null;
            $event->source = isset($data['source']) ? trim((string) $data['source']) : 'Manual';
            $event->created_by = $actorId;
            $event->save();

            if ($consignment !== null) {
                $this->applyStatus($consignment, $shipment, $type);
            } elseif ($type === CargoEventType::CUSTOMS_HOLD) {
                $shipment->status = ShipmentStatus::CUSTOMS_HOLD;
                $shipment->save();
            } elseif ($type === CargoEventType::DEPARTED && $shipment->status === ShipmentStatus::BOOKED) {
                $shipment->status = ShipmentStatus::IN_TRANSIT;
                $shipment->save();
            }

            $this->auditWriter->write(
                'shipment.event_recorded',
                'shipment',
                $shipmentId,
                ['eventType' => $type, 'consignmentId' => $consignment?->id],
            );

            $this->outboxWriter->record([
                'aggregate_type' => 'shipment',
                'aggregate_id' => $shipmentId,
                'event_type' => 'shipment.event_recorded',
                'payload' => ['eventType' => $type, 'consignmentId' => $consignment?->id],
            ]);

            return [
                'id' => $event->id,
                'shipmentId' => $shipmentId,
                'consignmentId' => $consignment?->id,
                'eventType' => $type,
                'location' => $location,
                'eventAt' => $event->event_at?->toIso8601String(),
                'shipmentStatus' => $shipment->status,
                'consignmentStatus' => $consignment?->status,
            ];
        });
    }

    private function applyStatus(Consignment $c, Shipment $s, string $type): void
    {
        $today = date('Y-m-d');

        if ($type === CargoEventType::DEPARTED) {
            $c->status = ConsignmentStatus::IN_TRANSIT;
            $leg = $c->legs()->orderBy('seq')->first();
            if ($leg !== null && $leg->actual_dep === null) {
                $leg->actual_dep = $today;
                $leg->save();
            }
            if ($s->status === ShipmentStatus::BOOKED) {
                $s->status = ShipmentStatus::IN_TRANSIT;
            }
        } elseif ($type === CargoEventType::PORT_ARRIVED) {
            $c->status = ConsignmentStatus::ARRIVED;
            $leg = $c->legs()->orderBy('seq')->first();
            if ($leg !== null && $leg->actual_arr === null) {
                $leg->actual_arr = $today;
                $leg->save();
            }
        } elseif ($type === CargoEventType::CUSTOMS_HOLD) {
            $c->status = ConsignmentStatus::CUSTOMS_HOLD;
            $s->status = ShipmentStatus::CUSTOMS_HOLD;
        } elseif ($type === CargoEventType::BOOKING_ROLLED) {
            $c->status = ConsignmentStatus::ROLLED;
        } elseif ($type === CargoEventType::CUSTOMS_RELEASED) {
            if (in_array($c->status, [ConsignmentStatus::CUSTOMS_HOLD, ConsignmentStatus::IN_TRANSIT], true)) {
                $c->status = ConsignmentStatus::ARRIVED;
            }
            if ($s->status === ShipmentStatus::CUSTOMS_HOLD) {
                $s->status = ShipmentStatus::CLEARED;
            }
        }

        $c->save();
        $s->save();
    }
}
