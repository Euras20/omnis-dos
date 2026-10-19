<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Queries;

use App\Models\AppUser;
use App\Shared\Company\CompanyContext;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/v1/overview
 *
 * One payload for the executive overview (first page after login).
 * Figures come from posted journals, open invoices, orders, stock and deliveries.
 * Customer portal users receive an empty payload.
 */
final class OverviewQuery
{
    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {
    }

    /**
     * @param  array{asOf?: ?string}  $filters
     * @return array<string, mixed>
     */
    public function execute(array $filters = []): array
    {
        $actorId = $this->companyContext->userId();
        $companyId = $this->companyContext->companyId();
        $asOf = isset($filters['asOf']) && is_string($filters['asOf']) && $filters['asOf'] !== ''
            ? $filters['asOf']
            : now()->toDateString();
        $monthStart = substr($asOf, 0, 7).'-01';

        $actor = AppUser::query()->where('id', $actorId)->first();
        if ($actor !== null && $actor->isPortal()) {
            return $this->empty($asOf, $monthStart);
        }

        $orderCounts = DB::table('sales_order')
            ->where('company_id', $companyId)
            ->selectRaw('status, COUNT(*)::int AS n')
            ->groupBy('status')
            ->pluck('n', 'status');

        $totalOrders = (int) $orderCounts->sum();
        $closed = (int) ($orderCounts['cancelled'] ?? 0) + (int) ($orderCounts['closed'] ?? 0);
        $open = $totalOrders - $closed;
        $awaiting = (int) ($orderCounts['draft'] ?? 0);

        $delayed = (int) DB::table('sales_order')
            ->where('company_id', $companyId)
            ->whereNotIn('status', ['cancelled', 'closed'])
            ->whereNotNull('required_date')
            ->where('required_date', '<', $asOf)
            ->count();

        $pendingAssignments = (int) DB::table('stock_assignment')
            ->where('company_id', $companyId)
            ->where('status', 'pending')
            ->count();

        $revenue = $this->accountNet($companyId, $monthStart, $asOf, ['4000', '4100']);
        $cogsCredit = $this->accountNet($companyId, $monthStart, $asOf, ['5000']);
        $cogs = -1 * $cogsCredit;
        $gross = $revenue - $cogs;

        $cash = $this->balanceAsOf($companyId, $asOf, ['1000', '1010', '1050']);
        $stockValue = (int) DB::table('stock_on_hand')
            ->where('company_id', $companyId)
            ->sum('value_minor');

        $overdueRow = DB::selectOne(
            'SELECT COALESCE(SUM(invoice_open(i.id, ?::date)), 0) AS open_minor
             FROM invoice i
             WHERE i.company_id = ? AND i.status = ? AND i.due_date < ?',
            [$asOf, $companyId, 'posted', $asOf]
        );
        $overdue = (int) ($overdueRow->open_minor ?? 0);

        $poCounts = DB::table('purchase_order')
            ->where('company_id', $companyId)
            ->selectRaw('status, COUNT(*)::int AS n')
            ->groupBy('status')
            ->pluck('n', 'status');

        $delivery = $this->deliveryPerformance($companyId, $asOf);

        return [
            'asOf' => $asOf,
            'period' => [
                'from' => $monthStart,
                'to' => $asOf,
            ],
            'orders' => [
                'total' => $totalOrders,
                'open' => $open,
                'delayed' => $delayed,
                'awaitingApproval' => $awaiting,
                'byStage' => $orderCounts->map(static fn ($n) => (int) $n)->all(),
            ],
            'assignmentsAwaiting' => $pendingAssignments,
            'month' => [
                'revenueMinor' => $revenue,
                'cogsMinor' => $cogs,
                'grossProfitMinor' => $gross,
                'grossMarginPercent' => $revenue > 0 ? round($gross / $revenue * 100, 2) : null,
            ],
            'cashMinor' => $cash,
            'stockValueMinor' => $stockValue,
            'receivablesOverdueMinor' => $overdue,
            'purchaseOrdersByStatus' => $poCounts->map(static fn ($n) => (int) $n)->all(),
            'delivery' => $delivery,
        ];
    }

    /**
     * Net (credit − debit) for the given account codes over a date range, posted journals only.
     *
     * @param  list<string>  $codes
     */
    private function accountNet(string $companyId, string $from, string $to, array $codes): int
    {
        $row = DB::table('journal_line')
            ->join('journal', 'journal.id', '=', 'journal_line.journal_id')
            ->join('account', 'account.id', '=', 'journal_line.account_id')
            ->where('journal_line.company_id', $companyId)
            ->where('journal.status', 'posted')
            ->whereBetween('journal.posting_date', [$from, $to])
            ->whereIn('account.code', $codes)
            ->selectRaw('COALESCE(SUM(journal_line.credit_minor - journal_line.debit_minor), 0) AS net')
            ->first();

        return (int) ($row->net ?? 0);
    }

    /**
     * Book balance (debit − credit) as at a date for the given account codes.
     *
     * @param  list<string>  $codes
     */
    private function balanceAsOf(string $companyId, string $asOf, array $codes): int
    {
        $row = DB::table('journal_line')
            ->join('journal', 'journal.id', '=', 'journal_line.journal_id')
            ->join('account', 'account.id', '=', 'journal_line.account_id')
            ->where('journal_line.company_id', $companyId)
            ->where('journal.status', 'posted')
            ->where('journal.posting_date', '<=', $asOf)
            ->whereIn('account.code', $codes)
            ->selectRaw('COALESCE(SUM(journal_line.debit_minor - journal_line.credit_minor), 0) AS bal')
            ->first();

        return (int) ($row->bal ?? 0);
    }

    /**
     * @return array{delivered: int, onTime: int, late: int, failed: int}
     */
    private function deliveryPerformance(string $companyId, string $asOf): array
    {
        $rows = DB::table('delivery_note')
            ->leftJoin('sales_order', 'sales_order.id', '=', 'delivery_note.sales_order_id')
            ->where('delivery_note.company_id', $companyId)
            ->whereIn('delivery_note.status', ['delivered', 'failed'])
            ->get(['delivery_note.status', 'delivery_note.delivered_at', 'sales_order.required_date']);

        $delivered = 0;
        $onTime = 0;
        $late = 0;
        $failed = 0;

        foreach ($rows as $row) {
            if ($row->status === 'failed') {
                $failed++;
                continue;
            }
            $delivered++;
            $deliveredDate = $row->delivered_at !== null ? substr((string) $row->delivered_at, 0, 10) : $asOf;
            $promise = $row->required_date !== null ? substr((string) $row->required_date, 0, 10) : null;
            if ($promise !== null && $deliveredDate <= $promise) {
                $onTime++;
            } else {
                $late++;
            }
        }

        return [
            'delivered' => $delivered,
            'onTime' => $onTime,
            'late' => $late,
            'failed' => $failed,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function empty(string $asOf, string $monthStart): array
    {
        return [
            'asOf' => $asOf,
            'period' => ['from' => $monthStart, 'to' => $asOf],
            'orders' => [
                'total' => 0,
                'open' => 0,
                'delayed' => 0,
                'awaitingApproval' => 0,
                'byStage' => [],
            ],
            'assignmentsAwaiting' => 0,
            'month' => [
                'revenueMinor' => 0,
                'cogsMinor' => 0,
                'grossProfitMinor' => 0,
                'grossMarginPercent' => null,
            ],
            'cashMinor' => 0,
            'stockValueMinor' => 0,
            'receivablesOverdueMinor' => 0,
            'purchaseOrdersByStatus' => [],
            'delivery' => [
                'delivered' => 0,
                'onTime' => 0,
                'late' => 0,
                'failed' => 0,
            ],
        ];
    }
}
