<?php

declare(strict_types=1);

namespace App\Modules\Sales\Queries;

use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/v1/finance/sales-activity
 *
 * Every sales transaction in a period: quotations, sales orders, invoices,
 * credit notes and receipts. Confirmed sales orders are available via
 * type=sales_order&status=confirmed (the sales-finance filtered query).
 */
final class SalesFinanceActivityQuery
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
        $this->permissionChecker->assertCan($actorId, 'finance.view');

        $companyId = $this->companyContext->companyId();
        $from = isset($filters['from']) ? (string) $filters['from'] : null;
        $to = isset($filters['to']) ? (string) $filters['to'] : null;
        $type = isset($filters['type']) ? (string) $filters['type'] : null;
        $status = isset($filters['status']) ? (string) $filters['status'] : null;
        $limit = min(max((int) ($filters['limit'] ?? 100), 1), 500);
        $offset = max((int) ($filters['offset'] ?? 0), 0);

        $rows = [];

        if ($type === null || $type === 'quotation') {
            $q = DB::table('quotation')->where('company_id', $companyId);
            if ($from) {
                $q->where('quotation_date', '>=', $from);
            }
            if ($to) {
                $q->where('quotation_date', '<=', $to);
            }
            if ($status) {
                $q->where('status', $status);
            }
            foreach ($q->orderByDesc('quotation_date')->limit(500)->get() as $r) {
                $rows[] = [
                    'date' => $r->quotation_date,
                    'type' => 'quotation',
                    'id' => $r->id,
                    'no' => $r->quotation_no,
                    'customerId' => $r->customer_id,
                    'amountMinor' => (int) $r->gross_minor,
                    'status' => $r->status,
                ];
            }
        }

        if ($type === null || $type === 'sales_order') {
            $q = DB::table('sales_order')->where('company_id', $companyId);
            if ($from) {
                $q->where('order_date', '>=', $from);
            }
            if ($to) {
                $q->where('order_date', '<=', $to);
            }
            if ($status) {
                $q->where('status', $status);
            }
            foreach ($q->orderByDesc('order_date')->limit(500)->get() as $r) {
                $rows[] = [
                    'date' => $r->order_date,
                    'type' => 'sales_order',
                    'id' => $r->id,
                    'no' => $r->order_no,
                    'customerId' => $r->customer_id,
                    'amountMinor' => (int) $r->gross_minor,
                    'status' => $r->status,
                ];
            }
        }

        if ($type === null || $type === 'invoice') {
            $q = DB::table('invoice')->where('company_id', $companyId);
            if ($from) {
                $q->where('invoice_date', '>=', $from);
            }
            if ($to) {
                $q->where('invoice_date', '<=', $to);
            }
            if ($status) {
                $q->where('status', $status);
            }
            foreach ($q->orderByDesc('invoice_date')->limit(500)->get() as $r) {
                $rows[] = [
                    'date' => $r->invoice_date,
                    'type' => 'invoice',
                    'id' => $r->id,
                    'no' => $r->invoice_no,
                    'customerId' => $r->customer_id,
                    'amountMinor' => (int) $r->gross_minor,
                    'status' => $r->status,
                ];
            }
        }

        if ($type === null || $type === 'credit_note') {
            $q = DB::table('credit_note')->where('company_id', $companyId);
            if ($from) {
                $q->where('credit_date', '>=', $from);
            }
            if ($to) {
                $q->where('credit_date', '<=', $to);
            }
            if ($status) {
                // credit_note has no status column; treat all as posted
                if ($status !== 'posted') {
                    $q->whereRaw('1 = 0');
                }
            }
            foreach ($q->orderByDesc('credit_date')->limit(500)->get() as $r) {
                $customerId = DB::table('invoice')->where('id', $r->invoice_id)->value('customer_id');
                $rows[] = [
                    'date' => $r->credit_date,
                    'type' => 'credit_note',
                    'id' => $r->id,
                    'no' => $r->credit_no,
                    'customerId' => $customerId,
                    'amountMinor' => -1 * (int) $r->gross_minor,
                    'status' => 'posted',
                ];
            }
        }

        if ($type === null || $type === 'receipt') {
            $q = DB::table('receipt')->where('company_id', $companyId);
            if ($from) {
                $q->where('receipt_date', '>=', $from);
            }
            if ($to) {
                $q->where('receipt_date', '<=', $to);
            }
            foreach ($q->orderByDesc('receipt_date')->limit(500)->get() as $r) {
                $rows[] = [
                    'date' => $r->receipt_date,
                    'type' => 'receipt',
                    'id' => $r->id,
                    'no' => $r->receipt_no,
                    'customerId' => $r->customer_id,
                    'amountMinor' => (int) $r->total_minor,
                    'status' => 'banked',
                ];
            }
        }

        usort($rows, static fn (array $a, array $b) => strcmp((string) $b['date'], (string) $a['date']));

        $total = count($rows);
        $page = array_slice($rows, $offset, $limit);

        return [
            'data' => array_values($page),
            'total' => $total,
        ];
    }
}
