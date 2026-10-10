<?php

declare(strict_types=1);

namespace App\Modules\Sales\Queries;

use App\Models\Quotation;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;

/**
 * GET /api/v1/quotations
 */
final class ListQuotationsQuery
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
        $this->permissionChecker->assertCan($actorId, 'sales.quotes.view');

        $companyId = $this->companyContext->companyId();
        $limit = min(max((int) ($filters['limit'] ?? 50), 1), 200);
        $offset = max((int) ($filters['offset'] ?? 0), 0);

        $q = Quotation::query()
            ->where('company_id', $companyId)
            ->orderByDesc('quotation_date')
            ->orderByDesc('created_at');

        if (! empty($filters['customerId'])) {
            $q->where('customer_id', (string) $filters['customerId']);
        }
        if (! empty($filters['status'])) {
            $q->where('status', (string) $filters['status']);
        }
        if (! empty($filters['from'])) {
            $q->where('quotation_date', '>=', (string) $filters['from']);
        }
        if (! empty($filters['to'])) {
            $q->where('quotation_date', '<=', (string) $filters['to']);
        }
        if (! empty($filters['q'])) {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['q']).'%';
            $q->where(function ($inner) use ($term) {
                $inner->where('quotation_no', 'ilike', $term)
                    ->orWhere('memo', 'ilike', $term);
            });
        }

        $total = (clone $q)->count();
        $rows = $q->limit($limit)->offset($offset)->get();

        return [
            'data' => $rows->map(static fn (Quotation $r) => [
                'id' => $r->id,
                'quotationNo' => $r->quotation_no,
                'customerId' => $r->customer_id,
                'quotationDate' => $r->quotation_date?->format('Y-m-d'),
                'validUntil' => $r->valid_until?->format('Y-m-d'),
                'status' => $r->status,
                'currency' => $r->currency,
                'netMinor' => $r->net_minor,
                'taxTotalMinor' => $r->tax_total_minor,
                'grossMinor' => $r->gross_minor,
                'convertedSalesOrderId' => $r->converted_sales_order_id,
            ])->all(),
            'total' => $total,
        ];
    }
}
