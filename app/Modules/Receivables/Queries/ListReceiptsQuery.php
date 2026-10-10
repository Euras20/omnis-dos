<?php

declare(strict_types=1);

namespace App\Modules\Receivables\Queries;

use App\Models\Receipt;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use Illuminate\Support\Facades\DB;

final class ListReceiptsQuery
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
        $this->permissionChecker->assertCan($actorId, 'ar.receipts.view');

        $companyId = $this->companyContext->companyId();
        $cutoff = (string) ($filters['cutoff'] ?? date('Y-m-d'));
        $limit = min(max((int) ($filters['limit'] ?? 50), 1), 200);
        $offset = max((int) ($filters['offset'] ?? 0), 0);

        $q = Receipt::query()
            ->where('company_id', $companyId)
            ->with('customer:id,code,name');

        if (! empty($filters['customerId'])) {
            $q->where('customer_id', $filters['customerId']);
        }
        if (! empty($filters['from'])) {
            $q->where('receipt_date', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $q->where('receipt_date', '<=', $filters['to']);
        }

        $total = (clone $q)->count();
        $rows = $q->orderByDesc('receipt_date')->orderByDesc('receipt_no')
            ->offset($offset)->limit($limit)->get();

        $items = [];
        foreach ($rows as $r) {
            $avail = (int) DB::selectOne(
                'SELECT receipt_available(?::uuid, ?::date) AS avail',
                [$r->id, $cutoff]
            )->avail;
            $items[] = [
                'id' => $r->id,
                'receiptNo' => $r->receipt_no,
                'customerId' => $r->customer_id,
                'customerName' => $r->customer?->name,
                'receiptDate' => $r->receipt_date?->format('Y-m-d'),
                'totalMinor' => $r->total_minor,
                'availableMinor' => max(0, $avail),
                'cashMinor' => $r->cash_minor,
                'whtMinor' => $r->wht_minor,
                'bankRef' => $r->bank_ref,
                'journalId' => $r->journal_id,
            ];
        }

        return ['items' => $items, 'total' => $total];
    }
}
