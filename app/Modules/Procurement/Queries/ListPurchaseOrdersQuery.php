<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Queries;

use App\Models\PurchaseOrder;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;

/**
 * GET /api/v1/purchase-orders — with lines and received quantities.
 */
final class ListPurchaseOrdersQuery
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
    ) {
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{data: list<array<string, mixed>>, total: int}
     */
    public function execute(array $filters = []): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'po.view');

        $companyId = $this->companyContext->companyId();
        $limit = min(max((int) ($filters['limit'] ?? 50), 1), 200);
        $offset = max((int) ($filters['offset'] ?? 0), 0);

        $q = PurchaseOrder::query()
            ->with('lines')
            ->where('company_id', $companyId)
            ->orderByDesc('order_date')
            ->orderByDesc('created_at');

        if (! empty($filters['supplierId'])) {
            $q->where('supplier_id', (string) $filters['supplierId']);
        }
        if (! empty($filters['status'])) {
            $q->where('status', (string) $filters['status']);
        }
        if (! empty($filters['from'])) {
            $q->where('order_date', '>=', (string) $filters['from']);
        }
        if (! empty($filters['to'])) {
            $q->where('order_date', '<=', (string) $filters['to']);
        }
        if (! empty($filters['q'])) {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['q']).'%';
            $q->where(function ($inner) use ($term) {
                $inner->where('po_no', 'ilike', $term)
                    ->orWhere('memo', 'ilike', $term);
            });
        }

        $total = (clone $q)->count();
        $rows = $q->limit($limit)->offset($offset)->get();

        return [
            'data' => $rows->map(static function (PurchaseOrder $po) {
                return [
                    'id' => $po->id,
                    'poNo' => $po->po_no,
                    'supplierId' => $po->supplier_id,
                    'orderDate' => $po->order_date?->format('Y-m-d'),
                    'expectedDate' => $po->expected_date?->format('Y-m-d'),
                    'status' => $po->status,
                    'currency' => $po->currency,
                    'netMinor' => $po->net_minor,
                    'taxTotalMinor' => $po->tax_total_minor,
                    'grossMinor' => $po->gross_minor,
                    'approvedAt' => $po->approved_at?->toIso8601String(),
                    'lines' => $po->lines->map(static fn ($l) => [
                        'id' => $l->id,
                        'lineNo' => $l->line_no,
                        'itemId' => $l->item_id,
                        'salesOrderLineId' => $l->sales_order_line_id,
                        'description' => $l->description,
                        'qty' => (string) $l->qty,
                        'qtyReceived' => (string) $l->qty_received,
                        'unitPriceMinor' => $l->unit_price_minor,
                        'lineCategory' => $l->line_category,
                    ])->all(),
                ];
            })->all(),
            'total' => $total,
        ];
    }
}
