<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Queries;

use App\Models\CloseWaiver;
use App\Models\PeriodClose;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/v1/close/{month}
 *
 * Computed close checks for a month. Exceptions that have a close_waiver for
 * the same period are reported as Waived. The lock watermark is the company
 * period_lock_date (also consulted by PeriodLockChecker).
 */
final class PeriodCloseChecklistQuery
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(string $month): array
    {
        $actorId = $this->companyContext->userId();
        if (! $this->permissionChecker->can($actorId, 'ledger.read')
            && ! $this->permissionChecker->can($actorId, 'period.close')) {
            $this->permissionChecker->assertCan($actorId, 'ledger.read');
        }

        $companyId = $this->companyContext->companyId();
        [$start, $end] = $this->monthBounds($month);

        $lockDate = DB::table('company')
            ->where('id', $companyId)
            ->value('period_lock_date');
        $lockedThrough = $lockDate !== null ? (string) $lockDate : null;
        $isLocked = $lockedThrough !== null && $end <= $lockedThrough;

        $waivers = CloseWaiver::query()
            ->where('company_id', $companyId)
            ->where('period_month', $month)
            ->get()
            ->keyBy('exception_code');

        $raw = $this->computeChecks($companyId, $start, $end);

        $checks = [];
        $openExceptions = 0;
        foreach ($raw as $check) {
            $code = $check['code'];
            $waiver = $waivers->get($code);
            $state = 'Passed';
            $waiverPayload = null;
            if ($check['issue'] !== null) {
                if ($waiver !== null) {
                    $state = 'Waived';
                    $waiverPayload = [
                        'reason' => $waiver->reason,
                        'evidenceRef' => $waiver->evidence_ref,
                        'waivedBy' => $waiver->waived_by,
                        'waivedAt' => $waiver->waived_at?->toIso8601String(),
                    ];
                } else {
                    $state = 'Exception';
                    $openExceptions++;
                }
            }
            $checks[] = [
                'code' => $code,
                'label' => $check['label'],
                'state' => $state,
                'issue' => $check['issue'],
                'waiver' => $waiverPayload,
            ];
        }

        $existing = PeriodClose::query()
            ->where('company_id', $companyId)
            ->where('period_month', $month)
            ->first();

        return [
            'month' => $month,
            'periodStart' => $start,
            'periodEnd' => $end,
            'lockedThrough' => $lockedThrough,
            'isLocked' => $isLocked,
            'status' => $existing?->status ?? ($isLocked ? 'closed' : 'open'),
            'checks' => $checks,
            'openExceptions' => $openExceptions,
            'canLock' => $openExceptions === 0 && ! $isLocked,
        ];
    }

    /**
     * @return list<array{code: string, label: string, issue: ?string}>
     */
    private function computeChecks(string $companyId, string $start, string $end): array
    {
        $balances = $this->accountBalances($companyId, $end);
        $totalDebit = 0;
        $totalCredit = 0;
        foreach ($balances as $row) {
            $net = (int) $row->net_minor;
            if ($net > 0) {
                $totalDebit += $net;
            } else {
                $totalCredit += -$net;
            }
        }
        $tbIssue = $totalDebit === $totalCredit
            ? null
            : sprintf('debits %d ≠ credits %d', $totalDebit, $totalCredit);

        $unmapped = [];
        foreach ($balances as $row) {
            if ((int) $row->net_minor === 0) {
                continue;
            }
            if ($row->category === null || $row->category === '' || $row->fs_line_item === null || $row->fs_line_item === '') {
                $unmapped[] = (string) $row->code;
            }
        }
        $unmappedIssue = $unmapped === [] ? null : implode(', ', $unmapped).' unmapped';

        $arSub = $this->sumInvoiceOpen($companyId, $end);
        $arCtl = (int) ($balances['1100']->net_minor ?? 0);
        $arIssue = $arSub === $arCtl
            ? null
            : sprintf('subledger %d vs control %d', $arSub, $arCtl);

        $apSub = $this->sumBillOpen($companyId, $end);
        $apCtl = -1 * (int) ($balances['2000']->net_minor ?? 0);
        $apIssue = $apSub === $apCtl
            ? null
            : sprintf('subledger %d vs control %d', $apSub, $apCtl);

        $advSub = $this->sumReceiptAvailable($companyId, $end);
        $advCtl = -1 * (int) ($balances['2100']->net_minor ?? 0);
        $advIssue = $advSub === $advCtl
            ? null
            : sprintf('register %d vs 2100 %d', $advSub, $advCtl);

        $stockValue = (int) DB::table('stock_on_hand')
            ->where('company_id', $companyId)
            ->sum('value_minor');
        $stockGl = (int) ($balances['1300']->net_minor ?? 0) + (int) ($balances['1340']->net_minor ?? 0);
        $stockIssue = $stockValue === $stockGl
            ? null
            : sprintf('stock %d vs GL %d', $stockValue, $stockGl);

        $draftCount = DB::table('journal')
            ->where('company_id', $companyId)
            ->where('status', 'draft')
            ->where('posting_date', '>=', $start)
            ->where('posting_date', '<=', $end)
            ->count();
        $draftIssue = $draftCount === 0 ? null : sprintf('%d draft journal(s) dated in the period', $draftCount);

        $payrollPending = DB::table('payroll_run')
            ->where('company_id', $companyId)
            ->where('period_month', substr($start, 0, 7))
            ->whereNotIn('status', ['posted', 'paid'])
            ->count();
        $payrollIssue = $payrollPending === 0 ? null : sprintf('%d payroll run(s) not posted or paid', $payrollPending);

        $bankUnmatched = DB::table('bank_statement_line')
            ->where('company_id', $companyId)
            ->where('status', 'unmatched')
            ->where('txn_date', '<=', $end)
            ->count();
        $bankIssue = $bankUnmatched === 0 ? null : sprintf('%d unmatched bank line(s) dated on or before period end', $bankUnmatched);

        $countsPending = DB::table('stock_count')
            ->where('company_id', $companyId)
            ->where('count_date', '<=', $end)
            ->where('status', '!=', 'posted')
            ->count();
        $countsIssue = $countsPending === 0 ? null : sprintf('%d stock count(s) not posted', $countsPending);

        $paysPending = DB::table('supplier_payment')
            ->where('company_id', $companyId)
            ->whereIn('status', ['prepared', 'pending'])
            ->where('payment_date', '<=', $end)
            ->count();
        $paysIssue = $paysPending === 0 ? null : sprintf('%d supplier payment(s) still prepared or pending', $paysPending);

        return [
            ['code' => 'tb', 'label' => 'Trial balance balances', 'issue' => $tbIssue],
            ['code' => 'unmapped', 'label' => 'Every account with a balance is mapped', 'issue' => $unmappedIssue],
            ['code' => 'ar', 'label' => 'Customer balances agree to AR control', 'issue' => $arIssue],
            ['code' => 'ap', 'label' => 'Open bills agree to AP control', 'issue' => $apIssue],
            ['code' => 'adv', 'label' => 'Unapplied customer money agrees to advances account', 'issue' => $advIssue],
            ['code' => 'stock', 'label' => 'Owned stock agrees to inventory accounts', 'issue' => $stockIssue],
            ['code' => 'drafts', 'label' => 'No draft journals dated in the period', 'issue' => $draftIssue],
            ['code' => 'payroll', 'label' => 'Payroll for the period is posted or paid', 'issue' => $payrollIssue],
            ['code' => 'bank', 'label' => 'Bank lines dated in the period are matched or excluded', 'issue' => $bankIssue],
            ['code' => 'counts', 'label' => 'Stock counts are posted', 'issue' => $countsIssue],
            ['code' => 'pays', 'label' => 'No prepared or pending supplier payments', 'issue' => $paysIssue],
        ];
    }

    /**
     * @return array<string, object>
     */
    private function accountBalances(string $companyId, string $asOf): array
    {
        $rows = DB::select(
            'SELECT a.code, a.category, a.fs_line_item,
                    COALESCE(SUM(jl.debit_minor - jl.credit_minor), 0) AS net_minor
             FROM account a
             LEFT JOIN journal_line jl ON jl.account_id = a.id AND jl.company_id = a.company_id
             LEFT JOIN journal j ON j.id = jl.journal_id AND j.status = \'posted\' AND j.posting_date <= ?
             WHERE a.company_id = ?
             GROUP BY a.code, a.category, a.fs_line_item',
            [$asOf, $companyId]
        );
        $byCode = [];
        foreach ($rows as $row) {
            $byCode[(string) $row->code] = $row;
        }

        return $byCode;
    }

    private function sumInvoiceOpen(string $companyId, string $asOf): int
    {
        $ids = DB::table('invoice')
            ->where('company_id', $companyId)
            ->where('status', 'posted')
            ->where('invoice_date', '<=', $asOf)
            ->pluck('id');
        $total = 0;
        foreach ($ids as $id) {
            $total += (int) DB::selectOne(
                'SELECT invoice_open(?::uuid, ?::date) AS open',
                [$id, $asOf]
            )->open;
        }

        return $total;
    }

    private function sumBillOpen(string $companyId, string $asOf): int
    {
        $ids = DB::table('bill')
            ->where('company_id', $companyId)
            ->where('status', 'posted')
            ->where('bill_date', '<=', $asOf)
            ->pluck('id');
        $total = 0;
        foreach ($ids as $id) {
            $total += (int) DB::selectOne(
                'SELECT bill_open(?::uuid, ?::date) AS open',
                [$id, $asOf]
            )->open;
        }

        return $total;
    }

    private function sumReceiptAvailable(string $companyId, string $asOf): int
    {
        $ids = DB::table('receipt')
            ->where('company_id', $companyId)
            ->where('receipt_date', '<=', $asOf)
            ->pluck('id');
        $total = 0;
        foreach ($ids as $id) {
            $total += (int) DB::selectOne(
                'SELECT receipt_available(?::uuid, ?::date) AS avail',
                [$id, $asOf]
            )->avail;
        }

        return $total;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function monthBounds(string $month): array
    {
        if (! preg_match('/^\d{4}-\d{2}$/', $month)) {
            throw new \InvalidArgumentException('Month must be YYYY-MM.');
        }
        $start = $month.'-01';
        $end = (new \DateTimeImmutable($start))->modify('last day of this month')->format('Y-m-d');

        return [$start, $end];
    }
}
