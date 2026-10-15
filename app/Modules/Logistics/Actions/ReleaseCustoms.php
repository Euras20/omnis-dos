<?php

declare(strict_types=1);

namespace App\Modules\Logistics\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\NotFoundException;
use App\Models\Consignment;
use App\Models\CustomsDeclaration;
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
 * POST /api/v1/customs/{id}/release — mark documents received and clear.
 *
 * Advances linked consignment out of Customs hold so it can be received.
 */
final class ReleaseCustoms
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
    public function execute(string $id, array $data): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'logistics.customs');

        $companyId = $this->companyContext->companyId();

        return DB::transaction(function () use ($id, $data, $companyId, $actorId) {
            $decl = CustomsDeclaration::query()
                ->where('company_id', $companyId)
                ->where('id', $id)
                ->lockForUpdate()
                ->first();

            if ($decl === null) {
                throw new NotFoundException('Customs declaration not found.');
            }

            if ($decl->status === 'Cleared') {
                throw new BusinessRuleException('Declaration is already cleared.');
            }

            $docs = is_array($decl->docs) ? $decl->docs : [];
            foreach (array_keys($docs) as $k) {
                $docs[$k] = true;
            }
            $decl->docs = $docs;
            $decl->status = 'Cleared';
            $decl->release_date = date('Y-m-d');
            $decl->payment = $data['payment'] ?? 'Paid';
            $decl->save();

            $shipment = Shipment::query()
                ->where('company_id', $companyId)
                ->where('id', $decl->shipment_id)
                ->lockForUpdate()
                ->first();

            if ($shipment !== null && $shipment->status === ShipmentStatus::CUSTOMS_HOLD) {
                $shipment->status = ShipmentStatus::CLEARED;
                $shipment->save();
            }

            if ($decl->consignment_id !== null) {
                $cn = Consignment::query()
                    ->where('company_id', $companyId)
                    ->where('id', $decl->consignment_id)
                    ->lockForUpdate()
                    ->first();

                if ($cn !== null && in_array($cn->status, [ConsignmentStatus::CUSTOMS_HOLD, ConsignmentStatus::IN_TRANSIT], true)) {
                    $cn->status = ConsignmentStatus::ARRIVED;
                    $cn->save();
                }
            }

            $event = new ShipmentEvent;
            $event->id = Uuid::v7();
            $event->company_id = $companyId;
            $event->shipment_id = $decl->shipment_id;
            $event->consignment_id = $decl->consignment_id;
            $event->event_at = now();
            $event->event_type = CargoEventType::CUSTOMS_RELEASED;
            $event->location = 'Customs';
            $event->source = $decl->declaration_no;
            $event->created_by = $actorId;
            $event->save();

            $this->auditWriter->write(
                'customs.released',
                'customs_declaration',
                $decl->id,
                ['declarationNo' => $decl->declaration_no],
            );

            $this->outboxWriter->record([
                'aggregate_type' => 'customs_declaration',
                'aggregate_id' => $decl->id,
                'event_type' => 'customs.released',
                'payload' => ['declarationNo' => $decl->declaration_no],
            ]);

            return [
                'id' => $decl->id,
                'declarationNo' => $decl->declaration_no,
                'status' => $decl->status,
                'releaseDate' => $decl->release_date?->format('Y-m-d'),
                'docs' => $decl->docs,
                'shipmentStatus' => $shipment?->status,
            ];
        });
    }
}
