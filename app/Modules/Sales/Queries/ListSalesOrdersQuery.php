<?php

declare(strict_types=1);

namespace App\Modules\Sales\Queries;

use App\Models\SalesOrder;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;

/**
 * GET /api/v1/sales-orders
 */
final class ListSalesOrdersQuery
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
        $this->permissionChecker->assertCan($actorId, 'sales.orders.view');

        $companyId = $this->companyContext->companyId();
        $limit = min(max((int) ($filters['limit'] ?? 50), 1), 200);
        $offset = max((int) ($filters['offset'] ?? 0), 0);

        $q = SalesOrder::query()
            ->with('lines')
            ->where('company_id', $companyId)
            ->orderByDesc('order_date')
            ->orderByDesc('created_at');

        if (! empty($filters['customerId'])) {
            $q->where('customer_id', (string) $filters['customerId']);
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
                $inner->where('order_no', 'ilike', $term)
                    ->orWhere('customer_po_ref', 'ilike', $term)
                    ->orWhere('memo', 'ilike', $term);
            });
        }

        $total = (clone $q)->count();
        $rows = $q->limit($limit)->offset($offset)->get();

        return [
            'data' => $rows->map(static function (SalesOrder $r) {
                $qtyOrdered = $r->lines->sum(fn ($l) => (float) $l->qty);
                $qtyDelivered = $r->lines->sum(fn ($l) => (float) $l->qty_delivered);
                $qtyInvoiced = $r->lines->sum(fn ($l) => (float) $l->qty_invoiced);

                return [
                    'id' => $r->id,
                    'orderNo' => $r->order_no,
                    'customerId' => $r->customer_id,
                    'customerPoRef' => $r->customer_po_ref,
                    'orderDate' => $r->order_date?->format('Y-m-d'),
                    'status' => $r->status,
                    'currency' => $r->currency,
                    'netMinor' => $r->net_minor,
                    'taxTotalMinor' => $r->tax_total_minor,
                    'grossMinor' => $r->gross_minor,
                    'qtyOrdered' => (string) $qtyOrdered,
                    'qtyDelivered' => (string) $qtyDelivered,
                    'qtyInvoiced' => (string) $qtyInvoiced,
                ];
            })->all(),
            'total' => $total,
        ];
    }
}
