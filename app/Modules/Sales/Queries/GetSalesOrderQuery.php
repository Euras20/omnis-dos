<?php

declare(strict_types=1);

namespace App\Modules\Sales\Queries;

use App\Exceptions\NotFoundException;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;

/**
 * GET /api/v1/sales-orders/{id} — with line-level delivered and invoiced quantities.
 */
final class GetSalesOrderQuery
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
        $this->permissionChecker->assertCan($actorId, 'sales.orders.view');

        $companyId = $this->companyContext->companyId();
        $order = SalesOrder::query()
            ->with('lines')
            ->where('company_id', $companyId)
            ->where('id', $id)
            ->first();

        if ($order === null) {
            throw new NotFoundException('Sales order not found.');
        }

        return [
            'id' => $order->id,
            'orderNo' => $order->order_no,
            'customerId' => $order->customer_id,
            'quotationId' => $order->quotation_id,
            'customerPoRef' => $order->customer_po_ref,
            'orderTypeId' => $order->order_type_id,
            'orderDate' => $order->order_date?->format('Y-m-d'),
            'requiredDate' => $order->required_date?->format('Y-m-d'),
            'currency' => $order->currency,
            'status' => $order->status,
            'netMinor' => $order->net_minor,
            'taxTotalMinor' => $order->tax_total_minor,
            'grossMinor' => $order->gross_minor,
            'memo' => $order->memo,
            'deliverTo' => $order->deliver_to,
            'confirmedAt' => $order->confirmed_at?->toIso8601String(),
            'lines' => $order->lines->map(static function (SalesOrderLine $l) {
                $qty = (float) $l->qty;
                $delivered = (float) $l->qty_delivered;
                $invoiced = (float) $l->qty_invoiced;

                return [
                    'id' => $l->id,
                    'lineNo' => $l->line_no,
                    'itemId' => $l->item_id,
                    'description' => $l->description,
                    'qty' => (string) $l->qty,
                    'unit' => $l->unit,
                    'unitPriceMinor' => $l->unit_price_minor,
                    'netMinor' => $l->net_minor,
                    'taxMinor' => $l->tax_minor,
                    'grossMinor' => $l->gross_minor,
                    'qtyDelivered' => (string) $l->qty_delivered,
                    'qtyInvoiced' => (string) $l->qty_invoiced,
                    // Remaining to deliver / remaining to invoice (independent).
                    'qtyOpen' => (string) max(0, $qty - $delivered),
                    'qtyRemainingToInvoice' => (string) max(0, $qty - $invoiced),
                ];
            })->all(),
            'createdAt' => $order->created_at?->toIso8601String(),
        ];
    }
}
