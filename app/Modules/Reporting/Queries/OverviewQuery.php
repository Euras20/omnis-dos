<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Queries;

use App\Models\AppUser;
use App\Modules\Ledger\Services\RoleResolver;
use App\Shared\Company\CompanyContext;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/v1/overview
 *
 * One payload for the executive overview (first page after login).
 * Revenue is the income class. COGS and cash come from the mapped roles.
 * Customer portal users receive an empty payload.
 */
final class OverviewQuery
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly RoleResolver $roleResolver,
    ) {
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function execute(array $filters = []): array
    {
        $user = AppUser::query()->find($this->companyContext->userId());
        if ($user !== null && $user->user_type === 'portal') {
            return $this->empty();
        }

        $companyId = $this->companyContext->companyId();
        $asOf = isset($filters['asOf']) && is_string($filters['asOf']) && $filters['asOf'] !== ''
            ? $filters['asOf']
            : now()->toDateString();
        $monthStart = substr($asOf, 0, 7).'-01';

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

        $revenue = $this->classNet($companyId, $monthStart, $asOf, 'income');
        $cogsAccount = $this->roleResolver->idOrNull($companyId, 'COGS');
        $cogs = $cogsAccount !== null
            ? -1 * $this->accountNet($companyId, $monthStart, $asOf, [$cogsAccount])
            : 0;
        $gross = $revenue - $cogs;

        $cashAccount = $this->roleResolver->idOrNull($companyId, 'BANK_CASH');
        $cash = $cashAccount !== null
            ? $this->balanceAsOf($companyId, $asOf, [$cashAccount])
            : 0;

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
            'delivery' => $this->deliveryPerformance($companyId, $asOf),
        ];
    }

    /**
     * Credit − debit for every posting account in a class.
     */
    private function classNet(string $companyId, string $from, string $to, string $category): int
    {
        $row = DB::table('journal_line')
            ->join('journal', 'journal.id', '=', 'journal_line.journal_id')
            ->join('account', 'account.id', '=', 'journal_line.account_id')
            ->where('journal_line.company_id', $companyId)
            ->where('journal.status', 'posted')
            ->whereBetween('journal.posting_date', [$from, $to])
            ->where('account.category', $category)
            ->where('account.node_type', 'POSTING')
            ->selectRaw('COALESCE(SUM(journal_line.credit_minor - journal_line.debit_minor), 0) AS net')
            ->first();

        return (int) ($row->net ?? 0);
    }

    /**
     * @param  list<string>  $accountIds
     */
    private function accountNet(string $companyId, string $from, string $to, array $accountIds): int
    {
        $row = DB::table('journal_line')
            ->join('journal', 'journal.id', '=', 'journal_line.journal_id')
            ->where('journal_line.company_id', $companyId)
            ->where('journal.status', 'posted')
            ->whereBetween('journal.posting_date', [$from, $to])
            ->whereIn('journal_line.account_id', $accountIds)
            ->selectRaw('COALESCE(SUM(journal_line.credit_minor - journal_line.debit_minor), 0) AS net')
            ->first();

        return (int) ($row->net ?? 0);
    }

    /**
     * @param  list<string>  $accountIds
     */
    private function balanceAsOf(string $companyId, string $asOf, array $accountIds): int
    {
        $row = DB::table('journal_line')
            ->join('journal', 'journal.id', '=', 'journal_line.journal_id')
            ->where('journal_line.company_id', $companyId)
            ->where('journal.status', 'posted')
            ->where('journal.posting_date', '<=', $asOf)
            ->whereIn('journal_line.account_id', $accountIds)
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
            ->whereDate('delivery_note.updated_at', '<=', $asOf)
            ->select('delivery_note.status', 'delivery_note.delivered_at', 'sales_order.required_date')
            ->get();

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
            if ($row->required_date !== null && $row->delivered_at !== null && $row->delivered_at > $row->required_date) {
                $late++;
            } else {
                $onTime++;
            }
        }

        return compact('delivered', 'onTime', 'late', 'failed');
    }

    /**
     * @return array<string, mixed>
     */
    private function empty(): array
    {
        return [
            'asOf' => now()->toDateString(),
            'period' => ['from' => null, 'to' => null],
            'orders' => ['total' => 0, 'open' => 0, 'delayed' => 0, 'awaitingApproval' => 0, 'byStage' => []],
            'assignmentsAwaiting' => 0,
            'month' => ['revenueMinor' => 0, 'cogsMinor' => 0, 'grossProfitMinor' => 0, 'grossMarginPercent' => null],
            'cashMinor' => 0,
            'stockValueMinor' => 0,
            'receivablesOverdueMinor' => 0,
            'purchaseOrdersByStatus' => [],
            'delivery' => ['delivered' => 0, 'onTime' => 0, 'late' => 0, 'failed' => 0],
        ];
    }
}
