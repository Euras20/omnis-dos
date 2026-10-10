<?php

declare(strict_types=1);

namespace App\Modules\Payables\Queries;

use App\Models\SupplierPayment;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;

final class ListSupplierPaymentsQuery
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
        $this->permissionChecker->assertCan($actorId, 'ap.payments.view');

        $companyId = $this->companyContext->companyId();
        $limit = min(max((int) ($filters['limit'] ?? 50), 1), 200);
        $offset = max((int) ($filters['offset'] ?? 0), 0);

        $q = SupplierPayment::query()
            ->where('company_id', $companyId)
            ->with(['supplier:id,code,name', 'allocations']);

        if (! empty($filters['supplierId'])) {
            $q->where('supplier_id', $filters['supplierId']);
        }
        if (! empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }

        $total = (clone $q)->count();
        $rows = $q->orderByDesc('created_at')
            ->offset($offset)->limit($limit)->get();

        $items = $rows->map(static fn (SupplierPayment $p) => [
            'id' => $p->id,
            'paymentNo' => $p->payment_no,
            'supplierId' => $p->supplier_id,
            'supplierName' => $p->supplier?->name,
            'status' => $p->status,
            'grossMinor' => $p->gross_minor,
            'whtMinor' => $p->wht_minor,
            'netMinor' => $p->net_minor,
            'bankRef' => $p->bank_ref,
            'paymentDate' => $p->payment_date?->format('Y-m-d'),
            'preparedBy' => $p->prepared_by,
            'confirmedBy' => $p->confirmed_by,
            'journalId' => $p->journal_id,
            'allocations' => $p->allocations->map(static fn ($a) => [
                'billId' => $a->bill_id,
                'amountMinor' => $a->amount_minor,
            ])->values()->all(),
        ])->values()->all();

        return ['items' => $items, 'total' => $total];
    }
}
