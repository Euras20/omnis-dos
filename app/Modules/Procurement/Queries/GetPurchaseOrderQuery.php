<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Queries;

use App\Exceptions\NotFoundException;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;

/**
 * GET /api/v1/purchase-orders/{id} — with lines and received quantities.
 */
final class GetPurchaseOrderQuery
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
        $this->permissionChecker->assertCan($actorId, 'po.view');

        $companyId = $this->companyContext->companyId();
        $po = PurchaseOrder::query()
            ->with(['lines', 'supplier:id,code,name'])
            ->where('company_id', $companyId)
            ->where('id', $id)
            ->first();

        if ($po === null) {
            throw new NotFoundException('Purchase order not found.');
        }

        return [
            'id' => $po->id,
            'poNo' => $po->po_no,
            'supplierId' => $po->supplier_id,
            'supplierName' => $po->supplier?->name,
            'orderDate' => $po->order_date?->format('Y-m-d'),
            'expectedDate' => $po->expected_date?->format('Y-m-d'),
            'status' => $po->status,
            'currency' => $po->currency,
            'fxRate' => $po->fx_rate !== null ? (string) $po->fx_rate : null,
            'netMinor' => $po->net_minor,
            'taxTotalMinor' => $po->tax_total_minor,
            'grossMinor' => $po->gross_minor,
            'memo' => $po->memo,
            'createdBy' => $po->created_by,
            'approvedBy' => $po->approved_by,
            'approvedAt' => $po->approved_at?->toIso8601String(),
            'lines' => $po->lines->map(static function (PurchaseOrderLine $l) {
                $qty = (float) $l->qty;
                $received = (float) $l->qty_received;

                return [
                    'id' => $l->id,
                    'lineNo' => $l->line_no,
                    'itemId' => $l->item_id,
                    'salesOrderLineId' => $l->sales_order_line_id,
                    'description' => $l->description,
                    'qty' => (string) $l->qty,
                    'unit' => $l->unit,
                    'unitPriceMinor' => $l->unit_price_minor,
                    'netMinor' => $l->net_minor,
                    'taxMinor' => $l->tax_minor,
                    'grossMinor' => $l->gross_minor,
                    'qtyReceived' => (string) $l->qty_received,
                    'qtyOpen' => (string) max(0, $qty - $received),
                    'lineCategory' => $l->line_category,
                ];
            })->all(),
            'createdAt' => $po->created_at?->toIso8601String(),
            'updatedAt' => $po->updated_at?->toIso8601String(),
        ];
    }
}
