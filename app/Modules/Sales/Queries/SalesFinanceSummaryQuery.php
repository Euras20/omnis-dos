<?php

declare(strict_types=1);

namespace App\Modules\Sales\Queries;

use App\Models\Invoice;
use App\Models\Receipt;
use App\Models\SalesOrder;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/v1/finance/sales-summary
 *
 * Sales workspace overview for the finance Sales screen: open quotations,
 * unpaid / overdue invoices, cash received in the last 30 days, and the
 * confirmed sales orders that belong under sales finance (filtered query).
 */
final class SalesFinanceSummaryQuery
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'finance.view');

        $companyId = $this->companyContext->companyId();
        $asOf = now()->toDateString();
        $paidFrom = now()->subDays(30)->toDateString();

        $openQuotes = DB::table('quotation')
            ->where('company_id', $companyId)
            ->whereIn('status', ['draft', 'sent', 'open'])
            ->selectRaw('COUNT(*) AS cnt, COALESCE(SUM(gross_minor), 0) AS total')
            ->first();

        $invoiceRows = Invoice::query()
            ->where('company_id', $companyId)
            ->where('status', 'posted')
            ->where('invoice_date', '<=', $asOf)
            ->get(['id', 'due_date', 'gross_minor']);

        $unpaidMinor = 0;
        $unpaidCount = 0;
        $overdueMinor = 0;
        $overdueCount = 0;
        foreach ($invoiceRows as $invoice) {
            $open = (int) DB::selectOne(
                'SELECT invoice_open(?::uuid, ?::date) AS open',
                [$invoice->id, $asOf]
            )->open;
            if ($open <= 0) {
                continue;
            }
            $unpaidMinor += $open;
            $unpaidCount++;
            $due = $invoice->due_date?->toDateString();
            if ($due !== null && $due < $asOf) {
                $overdueMinor += $open;
                $overdueCount++;
            }
        }

        $paid30 = (int) Receipt::query()
            ->where('company_id', $companyId)
            ->where('receipt_date', '>=', $paidFrom)
            ->where('receipt_date', '<=', $asOf)
            ->sum('total_minor');

        $confirmed = SalesOrder::query()
            ->with('lines')
            ->where('company_id', $companyId)
            ->where('status', 'confirmed')
            ->orderByDesc('order_date')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        $confirmedOrders = $confirmed->map(static function (SalesOrder $order) {
            $qtyOrdered = $order->lines->sum(fn ($l) => (float) $l->qty);
            $qtyDelivered = $order->lines->sum(fn ($l) => (float) $l->qty_delivered);
            $qtyInvoiced = $order->lines->sum(fn ($l) => (float) $l->qty_invoiced);

            return [
                'id' => $order->id,
                'orderNo' => $order->order_no,
                'customerId' => $order->customer_id,
                'orderDate' => $order->order_date?->toDateString(),
                'requiredDate' => $order->required_date?->toDateString(),
                'currency' => $order->currency,
                'status' => $order->status,
                'netMinor' => (int) $order->net_minor,
                'taxTotalMinor' => (int) $order->tax_total_minor,
                'grossMinor' => (int) $order->gross_minor,
                'qtyOrdered' => $qtyOrdered,
                'qtyDelivered' => $qtyDelivered,
                'qtyInvoiced' => $qtyInvoiced,
                'confirmedAt' => $order->confirmed_at?->toIso8601String(),
            ];
        })->all();

        return [
            'asOf' => $asOf,
            'kpis' => [
                'openQuotationsMinor' => (int) ($openQuotes->total ?? 0),
                'openQuotationsCount' => (int) ($openQuotes->cnt ?? 0),
                'unpaidInvoicesMinor' => $unpaidMinor,
                'unpaidInvoicesCount' => $unpaidCount,
                'overdueInvoicesMinor' => $overdueMinor,
                'overdueInvoicesCount' => $overdueCount,
                'paidLast30DaysMinor' => $paid30,
            ],
            'confirmedOrders' => $confirmedOrders,
            'confirmedOrdersCount' => count($confirmedOrders),
        ];
    }
}
