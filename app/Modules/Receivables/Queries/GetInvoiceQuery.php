<?php

declare(strict_types=1);

namespace App\Modules\Receivables\Queries;

use App\Exceptions\NotFoundException;
use App\Models\Invoice;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use Illuminate\Support\Facades\DB;

final class GetInvoiceQuery
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
        $this->permissionChecker->assertCan($actorId, 'ar.invoices.view');

        $companyId = $this->companyContext->companyId();
        $inv = Invoice::query()
            ->where('company_id', $companyId)
            ->where('id', $id)
            ->with(['lines', 'customer:id,code,name', 'creditNotes', 'allocations.receipt'])
            ->first();
        if ($inv === null) {
            throw new NotFoundException('Invoice not found.');
        }

        $open = (int) DB::selectOne(
            'SELECT invoice_open(?::uuid, CURRENT_DATE) AS open',
            [$inv->id]
        )->open;

        return [
            'id' => $inv->id,
            'invoiceNo' => $inv->invoice_no,
            'customerId' => $inv->customer_id,
            'customerName' => $inv->customer?->name,
            'salesOrderId' => $inv->sales_order_id,
            'invoiceDate' => $inv->invoice_date?->format('Y-m-d'),
            'dueDate' => $inv->due_date?->format('Y-m-d'),
            'currency' => $inv->currency,
            'netMinor' => $inv->net_minor,
            'tax' => [
                'vat' => $inv->vat_minor,
                'nhil' => $inv->nhil_minor,
                'getfund' => $inv->getfund_minor,
                'covid' => $inv->covid_minor,
                'total' => $inv->tax_total_minor,
            ],
            'grossMinor' => $inv->gross_minor,
            'openMinor' => max(0, $open),
            'status' => $inv->status,
            'journalId' => $inv->journal_id,
            'memo' => $inv->memo,
            'lines' => $inv->lines->map(static fn ($l) => [
                'id' => $l->id,
                'lineNo' => $l->line_no,
                'itemId' => $l->item_id,
                'description' => $l->description,
                'qty' => (float) $l->qty,
                'unit' => $l->unit,
                'unitPriceMinor' => $l->unit_price_minor,
                'netMinor' => $l->net_minor,
                'grossMinor' => $l->gross_minor,
            ])->values()->all(),
            'creditNotes' => $inv->creditNotes->map(static fn ($c) => [
                'id' => $c->id,
                'creditNo' => $c->credit_no,
                'creditDate' => $c->credit_date?->format('Y-m-d'),
                'grossMinor' => $c->gross_minor,
                'journalId' => $c->journal_id,
            ])->values()->all(),
            'settlements' => $inv->allocations->map(static fn ($a) => [
                'receiptId' => $a->receipt_id,
                'receiptNo' => $a->receipt?->receipt_no,
                'amountMinor' => $a->amount_minor,
                'allocatedAt' => $a->allocated_at?->format('Y-m-d'),
            ])->values()->all(),
            'createdAt' => $inv->created_at?->toIso8601String(),
        ];
    }
}
