<?php

declare(strict_types=1);

namespace App\Modules\Logistics\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\NotFoundException;
use App\Models\Consignment;
use App\Models\Shipment;
use App\Modules\Logistics\Domain\ConsignmentStatus;
use App\Modules\Logistics\Domain\ShipmentStatus;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/shipments/{id}/consignments/{cid}/receive
 *
 * Marks a consignment received (after arrival / customs clearance).
 * Actual GRN posting remains on the existing goods-receipts endpoint;
 * this action only advances the logistics status and optionally stores the GRN id.
 */
final class MarkConsignmentReceived
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly AuditWriter $auditWriter,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function execute(string $shipmentId, string $consignmentId, array $data): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'logistics.manage');

        $companyId = $this->companyContext->companyId();

        return DB::transaction(function () use ($shipmentId, $consignmentId, $data, $companyId) {
            $shipment = Shipment::query()
                ->where('company_id', $companyId)
                ->where('id', $shipmentId)
                ->lockForUpdate()
                ->first();

            if ($shipment === null) {
                throw new NotFoundException('Shipment not found.');
            }

            $consignment = Consignment::query()
                ->where('company_id', $companyId)
                ->where('id', $consignmentId)
                ->where('shipment_id', $shipmentId)
                ->lockForUpdate()
                ->first();

            if ($consignment === null) {
                throw new NotFoundException('Consignment not found.');
            }

            if ($consignment->status === ConsignmentStatus::CUSTOMS_HOLD) {
                throw new BusinessRuleException('Customs release required before receiving.');
            }

            if (! in_array($consignment->status, [ConsignmentStatus::ARRIVED, ConsignmentStatus::IN_TRANSIT, ConsignmentStatus::BOOKED], true)) {
                if ($consignment->status === ConsignmentStatus::RECEIVED) {
                    throw new BusinessRuleException('Consignment is already received.');
                }
            }

            $consignment->status = ConsignmentStatus::RECEIVED;
            if (isset($data['goodsReceiptId']) && $data['goodsReceiptId'] !== '') {
                $consignment->goods_receipt_id = (string) $data['goodsReceiptId'];
            }
            $consignment->save();

            $this->refreshShipmentStatus($shipment);

            $this->auditWriter->write(
                'consignment.received',
                'shipment',
                $shipmentId,
                ['consignmentId' => $consignmentId],
            );

            return [
                'id' => $consignment->id,
                'consignmentNo' => $consignment->consignment_no,
                'status' => $consignment->status,
                'shipmentStatus' => $shipment->status,
                'goodsReceiptId' => $consignment->goods_receipt_id,
            ];
        });
    }

    private function refreshShipmentStatus(Shipment $shipment): void
    {
        $statuses = Consignment::query()
            ->where('shipment_id', $shipment->id)
            ->pluck('status')
            ->all();

        $received = count(array_filter($statuses, static fn ($s) => $s === ConsignmentStatus::RECEIVED));
        $total = count($statuses);

        if ($received === $total && $total > 0) {
            $shipment->status = ShipmentStatus::RECEIVED;
        } elseif ($received > 0) {
            $shipment->status = ShipmentStatus::PARTIALLY_RECEIVED;
        }

        $shipment->save();
    }
}
