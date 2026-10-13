<?php

declare(strict_types=1);

namespace App\Modules\Receivables\Queries;

use App\Models\Invoice;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use Illuminate\Support\Facades\DB;

final class ListInvoicesQuery
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
    ) {
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function execute(array $filters = []): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'ar.invoices.view');

        $companyId = $this->companyContext->companyId();
        $cutoff = (string) ($filters['cutoff'] ?? date('Y-m-d'));
        $limit = min(max((int) ($filters['limit'] ?? 50), 1), 200);
        $offset = max((int) ($filters['offset'] ?? 0), 0);

        $q = Invoice::query()
            ->where('company_id', $companyId)
            ->with('customer:id,code,name');

        if (! empty($filters['customerId'])) {
            $q->where('customer_id', $filters['customerId']);
        }
        if (! empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }
        if (! empty($filters['salesOrderId'])) {
            $q->where('sales_order_id', $filters['salesOrderId']);
        }
        if (! empty($filters['from'])) {
            $q->where('invoice_date', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $q->where('invoice_date', '<=', $filters['to']);
        }
        if (! empty($filters['q'])) {
            $term = '%' . $filters['q'] . '%';
            $q->where(function ($qq) use ($term) {
                $qq->where('invoice_no', 'ilike', $term)
                    ->orWhere('memo', 'ilike', $term);
            });
        }

        $total = (clone $q)->count();
        $rows = $q->orderByDesc('invoice_date')->orderByDesc('invoice_no')
            ->offset($offset)->limit($limit)->get();

        $items = [];
        foreach ($rows as $inv) {
            $open = (int) DB::selectOne(
                'SELECT invoice_open(?::uuid, ?::date) AS open',
                [$inv->id, $cutoff]
            )->open;
            $items[] = [
                'id' => $inv->id,
                'invoiceNo' => $inv->invoice_no,
                'customerId' => $inv->customer_id,
                'customerName' => $inv->customer?->name,
                'salesOrderId' => $inv->sales_order_id,
                'invoiceDate' => $inv->invoice_date?->format('Y-m-d'),
                'dueDate' => $inv->due_date?->format('Y-m-d'),
                'currency' => $inv->currency,
                'grossMinor' => $inv->gross_minor,
                'openMinor' => max(0, $open),
                'status' => $inv->status,
                'journalId' => $inv->journal_id,
            ];
        }

        return ['items' => $items, 'total' => $total];
    }
}
