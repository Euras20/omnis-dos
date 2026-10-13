<?php

declare(strict_types=1);

namespace App\Modules\Payables\Queries;

use App\Exceptions\NotFoundException;
use App\Models\Bill;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/v1/bills/{id} — header fields with open balance at optional cutoff.
 */
final class GetBillQuery
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
    ) {
    }

    /**
     * @param  array<string, mixed>  $options  optional cutoff (Y-m-d)
     * @return array<string, mixed>
     */
    public function execute(string $id, array $options = []): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'ap.bills.view');

        $companyId = $this->companyContext->companyId();
        $cutoff = (string) ($options['cutoff'] ?? date('Y-m-d'));

        $bill = Bill::query()
            ->where('company_id', $companyId)
            ->where('id', $id)
            ->with(['supplier:id,code,name', 'lines'])
            ->first();

        if ($bill === null) {
            throw new NotFoundException('Bill not found.');
        }

        $open = (int) DB::selectOne(
            'SELECT bill_open(?::uuid, ?::date) AS open',
            [$bill->id, $cutoff]
        )->open;

        return [
            'id' => $bill->id,
            'billNo' => $bill->bill_no,
            'supplierId' => $bill->supplier_id,
            'supplierName' => $bill->supplier?->name,
            'supplierRef' => $bill->supplier_ref,
            'purchaseOrderId' => $bill->purchase_order_id,
            'billDate' => $bill->bill_date?->format('Y-m-d'),
            'dueDate' => $bill->due_date?->format('Y-m-d'),
            'currency' => $bill->currency,
            'netMinor' => $bill->net_minor,
            'taxMinor' => $bill->tax_minor,
            'grossMinor' => $bill->gross_minor,
            'openMinor' => max(0, $open),
            'expenseAccountId' => $bill->expense_account_id,
            'status' => $bill->status,
            'journalId' => $bill->journal_id,
            'memo' => $bill->memo,
            'createdBy' => $bill->created_by,
            'lines' => $bill->lines->map(static fn ($l) => [
                'id' => $l->id,
                'lineNo' => $l->line_no,
                'purchaseOrderLineId' => $l->purchase_order_line_id,
                'itemId' => $l->item_id,
                'description' => $l->description,
                'qty' => (string) $l->qty,
                'unit' => $l->unit,
                'unitPriceMinor' => $l->unit_price_minor,
                'netMinor' => $l->net_minor,
                'taxMinor' => $l->tax_minor,
                'grossMinor' => $l->gross_minor,
            ])->all(),
            'createdAt' => $bill->created_at?->toIso8601String(),
            'updatedAt' => $bill->updated_at?->toIso8601String(),
        ];
    }
}
