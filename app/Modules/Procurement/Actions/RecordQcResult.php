<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\NotFoundException;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\Location;
use App\Modules\Inventory\Domain\MovementRequest;
use App\Modules\Inventory\Domain\MovementType;
use App\Modules\Inventory\Services\MovementService;
use App\Modules\Procurement\Domain\QcStatus;
use App\Modules\Quality\Actions\CreateNonconformance;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/qc/{id} — record QC for a goods receipt line.
 * Moves certified stock to store/cross-dock when storeLocationId is provided and differs.
 */
final class RecordQcResult
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly MovementService $movementService,
        private readonly AuditWriter $auditWriter,
        private readonly CreateNonconformance $createNonconformance,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function execute(string $goodsReceiptLineId, array $data): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'stock.move');

        $companyId = $this->companyContext->companyId();
        $qtyCertified = (string) ($data['qtyCertified'] ?? '0');
        $qtyQuarantine = (string) ($data['qtyQuarantine'] ?? '0');
        $qtyRejected = (string) ($data['qtyRejected'] ?? '0');
        $storeLocationId = isset($data['storeLocationId']) ? (string) $data['storeLocationId'] : null;
        $qcNote = isset($data['qcNote']) ? trim((string) $data['qcNote']) : null;
        $requestId = $this->companyContext->requestId();

        foreach (['qtyCertified' => $qtyCertified, 'qtyQuarantine' => $qtyQuarantine, 'qtyRejected' => $qtyRejected] as $label => $val) {
            if ($val === '' || ! is_numeric($val) || (float) $val < 0) {
                throw new BusinessRuleException("{$label} must be a non-negative number.");
            }
        }

        return DB::transaction(function () use (
            $goodsReceiptLineId,
            $companyId,
            $qtyCertified,
            $qtyQuarantine,
            $qtyRejected,
            $storeLocationId,
            $qcNote,
            $actorId,
            $requestId,
        ) {
            $line = GoodsReceiptLine::query()
                ->where('company_id', $companyId)
                ->where('id', $goodsReceiptLineId)
                ->lockForUpdate()
                ->first();

            if ($line === null) {
                throw new NotFoundException('Goods receipt line not found.');
            }

            if ($line->qc_status !== QcStatus::PENDING && $line->qc_status !== QcStatus::PARTIAL) {
                throw new BusinessRuleException("QC already completed for this line (status: {$line->qc_status}).");
            }

            $received = (float) $line->qty_received;
            $sum = (float) $qtyCertified + (float) $qtyQuarantine + (float) $qtyRejected;
            if ($sum > $received + 0.0005) {
                throw new BusinessRuleException('QC quantities cannot exceed qty received.');
            }
            if ($sum <= 0) {
                throw new BusinessRuleException('At least one QC quantity must be positive.');
            }

            $grn = GoodsReceipt::query()
                ->where('company_id', $companyId)
                ->where('id', $line->goods_receipt_id)
                ->first();
            if ($grn === null) {
                throw new NotFoundException('Goods receipt not found.');
            }

            $status = QcStatus::PARTIAL;
            if (abs($sum - $received) < 0.0005) {
                if ((float) $qtyCertified > 0 && (float) $qtyQuarantine == 0.0 && (float) $qtyRejected == 0.0) {
                    $status = QcStatus::CERTIFIED;
                } elseif ((float) $qtyRejected > 0 && (float) $qtyCertified == 0.0 && (float) $qtyQuarantine == 0.0) {
                    $status = QcStatus::REJECTED;
                } elseif ((float) $qtyQuarantine > 0 && (float) $qtyCertified == 0.0 && (float) $qtyRejected == 0.0) {
                    $status = QcStatus::QUARANTINE;
                } else {
                    $status = QcStatus::PARTIAL;
                }
            }

            // Transfer certified qty from receiving location to store when different.
            if ((float) $qtyCertified > 0 && $storeLocationId !== null && $storeLocationId !== '' && $storeLocationId !== $grn->location_id) {
                $store = Location::query()
                    ->where('company_id', $companyId)
                    ->where('id', $storeLocationId)
                    ->where('is_active', true)
                    ->first();
                if ($store === null) {
                    throw new NotFoundException('Store location not found.');
                }

                $this->movementService->record(new MovementRequest(
                    companyId: $companyId,
                    movementType: MovementType::TRANSFER_OUT,
                    itemId: $line->item_id,
                    locationId: $grn->location_id,
                    lot: $line->lot,
                    qty: number_format(-(float) $qtyCertified, 3, '.', ''),
                    unitCostMinor: $line->unit_cost_minor,
                    movementDate: $grn->receipt_date->format('Y-m-d'),
                    sourceType: 'qc',
                    sourceId: $line->id,
                    salesOrderLineId: null,
                    goodsReceiptLineId: $line->id,
                    stockAssignmentId: null,
                    journalId: null,
                    memo: 'QC certified transfer out',
                    actorId: $actorId,
                    requestId: $requestId,
                ));

                $this->movementService->record(new MovementRequest(
                    companyId: $companyId,
                    movementType: MovementType::TRANSFER_IN,
                    itemId: $line->item_id,
                    locationId: $storeLocationId,
                    lot: $line->lot,
                    qty: $qtyCertified,
                    unitCostMinor: $line->unit_cost_minor,
                    movementDate: $grn->receipt_date->format('Y-m-d'),
                    sourceType: 'qc',
                    sourceId: $line->id,
                    salesOrderLineId: null,
                    goodsReceiptLineId: $line->id,
                    stockAssignmentId: null,
                    journalId: null,
                    memo: 'QC certified transfer in',
                    actorId: $actorId,
                    requestId: $requestId,
                ));
            }

            $line->qty_certified = $qtyCertified;
            $line->qty_quarantine = $qtyQuarantine;
            $line->qty_rejected = $qtyRejected;
            $line->qc_status = $status;
            $line->store_location_id = $storeLocationId ?: null;
            $line->qc_note = $qcNote;
            $line->qc_by = $actorId;
            $line->qc_at = now();
            $line->save();

            $ncr = null;
            if ((float) $qtyQuarantine > 0 || (float) $qtyRejected > 0) {
                $issue = $qcNote !== null && $qcNote !== ''
                    ? $qcNote
                    : ((float) $qtyRejected > 0 ? 'Rejected on arrival inspection' : 'Quarantined on arrival inspection');
                $ncr = $this->createNonconformance->execute([
                    'issue' => $issue,
                    'cause' => 'Under investigation with supplier',
                    'action' => (float) $qtyQuarantine > 0 ? 'Re-inspect quarantined units' : 'Return rejected units to supplier',
                    'sourceType' => 'qc_line',
                    'sourceId' => $line->id,
                    'itemId' => $line->item_id,
                    'sku' => null,
                    'qty' => (string) ((float) $qtyQuarantine + (float) $qtyRejected),
                    'dueDate' => now()->addDays(7)->format('Y-m-d'),
                    'ownerId' => $actorId,
                ], skipPermissionCheck: true);
            }

            $this->auditWriter->write(
                action: 'goods_receipt.qc_recorded',
                entityType: 'goods_receipt_line',
                entityId: $line->id,
                detail: [
                    'goodsReceiptId' => $line->goods_receipt_id,
                    'qcStatus' => $status,
                    'qtyCertified' => $qtyCertified,
                    'qtyQuarantine' => $qtyQuarantine,
                    'qtyRejected' => $qtyRejected,
                ],
                actorId: $actorId,
            );

            return [
                'id' => $line->id,
                'goodsReceiptId' => $line->goods_receipt_id,
                'qcStatus' => $line->qc_status,
                'qtyReceived' => (string) $line->qty_received,
                'qtyCertified' => (string) $line->qty_certified,
                'qtyQuarantine' => (string) $line->qty_quarantine,
                'qtyRejected' => (string) $line->qty_rejected,
                'storeLocationId' => $line->store_location_id,
                'qcAt' => $line->qc_at?->toIso8601String(),
                'nonconformance' => $ncr,
            ];
        });
    }
}
