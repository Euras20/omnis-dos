<?php

declare(strict_types=1);

namespace App\Modules\Payables\Queries;

use App\Models\Bill;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use Illuminate\Support\Facades\DB;

final class ListBillsQuery
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
        $this->permissionChecker->assertCan($actorId, 'ap.bills.view');

        $companyId = $this->companyContext->companyId();
        $cutoff = (string) ($filters['cutoff'] ?? date('Y-m-d'));
        $limit = min(max((int) ($filters['limit'] ?? 50), 1), 200);
        $offset = max((int) ($filters['offset'] ?? 0), 0);

        $q = Bill::query()
            ->where('company_id', $companyId)
            ->with('supplier:id,code,name');

        if (! empty($filters['supplierId'])) {
            $q->where('supplier_id', $filters['supplierId']);
        }
        if (! empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }
        if (! empty($filters['from'])) {
            $q->where('bill_date', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $q->where('bill_date', '<=', $filters['to']);
        }

        $total = (clone $q)->count();
        $rows = $q->orderByDesc('bill_date')->orderByDesc('bill_no')
            ->offset($offset)->limit($limit)->get();

        $items = [];
        foreach ($rows as $b) {
            $open = (int) DB::selectOne(
                'SELECT bill_open(?::uuid, ?::date) AS open',
                [$b->id, $cutoff]
            )->open;
            $items[] = [
                'id' => $b->id,
                'billNo' => $b->bill_no,
                'supplierId' => $b->supplier_id,
                'supplierName' => $b->supplier?->name,
                'supplierRef' => $b->supplier_ref,
                'billDate' => $b->bill_date?->format('Y-m-d'),
                'dueDate' => $b->due_date?->format('Y-m-d'),
                'grossMinor' => $b->gross_minor,
                'openMinor' => max(0, $open),
                'status' => $b->status,
                'journalId' => $b->journal_id,
            ];
        }

        return ['items' => $items, 'total' => $total];
    }
}
