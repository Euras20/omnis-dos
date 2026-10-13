<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Queries;

use App\Exceptions\NotFoundException;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;

/**
 * GET /api/v1/goods-receipts/{id} — header + lines including QC quantities.
 */
final class GetGoodsReceiptQuery
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
        $this->permissionChecker->assertCan($actorId, 'stock.move');

        $companyId = $this->companyContext->companyId();
        $grn = GoodsReceipt::query()
            ->with(['lines', 'purchaseOrder:id,po_no,supplier_id', 'location:id,code,name'])
            ->where('company_id', $companyId)
            ->where('id', $id)
            ->first();

        if ($grn === null) {
            throw new NotFoundException('Goods receipt not found.');
        }

        return [
            'id' => $grn->id,
            'grnNo' => $grn->grn_no,
            'purchaseOrderId' => $grn->purchase_order_id,
            'poNo' => $grn->purchaseOrder?->po_no,
            'supplierId' => $grn->purchaseOrder?->supplier_id,
            'locationId' => $grn->location_id,
            'locationCode' => $grn->location?->code,
            'locationName' => $grn->location?->name,
            'receiptDate' => $grn->receipt_date?->format('Y-m-d'),
            'status' => $grn->status,
            'journalId' => $grn->journal_id,
            'memo' => $grn->memo,
            'createdBy' => $grn->created_by,
            'lines' => $grn->lines->map(static fn (GoodsReceiptLine $l) => [
                'id' => $l->id,
                'lineNo' => $l->line_no,
                'purchaseOrderLineId' => $l->purchase_order_line_id,
                'itemId' => $l->item_id,
                'qtyReceived' => (string) $l->qty_received,
                'qtyCertified' => (string) $l->qty_certified,
                'qtyQuarantine' => (string) $l->qty_quarantine,
                'qtyRejected' => (string) $l->qty_rejected,
                'lot' => $l->lot,
                'unitCostMinor' => $l->unit_cost_minor,
                'qcStatus' => $l->qc_status,
                'storeLocationId' => $l->store_location_id,
                'stockMovementId' => $l->stock_movement_id,
                'qcNote' => $l->qc_note,
                'qcBy' => $l->qc_by,
                'qcAt' => $l->qc_at?->toIso8601String(),
            ])->all(),
            'createdAt' => $grn->created_at?->toIso8601String(),
            'updatedAt' => $grn->updated_at?->toIso8601String(),
        ];
    }
}
