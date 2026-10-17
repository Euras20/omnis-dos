<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Queries;

use App\Models\Account;
use App\Models\ExchangeRate;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/v1/reports/trial-balance
 *
 * Posted balances by account as at a date, with FS mapping and normal side.
 * Optional presentation currency uses the latest mid rate on or before asOf.
 */
final class TrialBalanceQuery
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
    ) {
    }

    /**
     * @param  array{asOf?: ?string, currency?: ?string}  $filters
     * @return array<string, mixed>
     */
    public function execute(array $filters = []): array
    {
        $actorId = $this->companyContext->userId();
        if (! $this->permissionChecker->can($actorId, 'ledger.read')
            && ! $this->permissionChecker->can($actorId, 'reports.financial')) {
            $this->permissionChecker->assertCan($actorId, 'ledger.read');
        }

        $companyId = $this->companyContext->companyId();
        $asOf = isset($filters['asOf']) && is_string($filters['asOf']) && $filters['asOf'] !== ''
            ? $filters['asOf']
            : now()->toDateString();
        $currency = isset($filters['currency']) && is_string($filters['currency']) && $filters['currency'] !== ''
            ? strtoupper($filters['currency'])
            : null;

        $accounts = Account::query()
            ->where('company_id', $companyId)
            ->orderBy('code')
            ->get();

        $balances = DB::table('journal_line')
            ->select([
                'journal_line.account_id',
                DB::raw('COALESCE(SUM(journal_line.debit_minor), 0) as total_debit'),
                DB::raw('COALESCE(SUM(journal_line.credit_minor), 0) as total_credit'),
            ])
            ->join('journal', 'journal.id', '=', 'journal_line.journal_id')
            ->where('journal_line.company_id', $companyId)
            ->where('journal.status', 'posted')
            ->where('journal.posting_date', '<=', $asOf)
            ->groupBy('journal_line.account_id')
            ->get()
            ->keyBy('account_id');

        $rate = null;
        $rateDate = null;
        if ($currency !== null && $currency !== 'GHS') {
            $fx = ExchangeRate::query()
                ->where('company_id', $companyId)
                ->where('currency', $currency)
                ->where('rate_date', '<=', $asOf)
                ->orderByDesc('rate_date')
                ->first();
            if ($fx !== null) {
                $rate = (float) $fx->mid_rate;
                $rateDate = $fx->rate_date?->format('Y-m-d');
            }
        }

        $items = [];
        $totalDebit = 0;
        $totalCredit = 0;

        foreach ($accounts as $account) {
            $bal = $balances->get($account->id);
            $debit = $bal ? (int) $bal->total_debit : 0;
            $credit = $bal ? (int) $bal->total_credit : 0;
            $net = $debit - $credit; // positive = debit balance

            if ($debit === 0 && $credit === 0) {
                continue;
            }

            $normal = strtolower((string) $account->normal_balance);
            $normalBalanceMinor = $normal === 'credit' ? -$net : $net;

            $item = [
                'code' => $account->code,
                'name' => $account->name,
                'category' => $account->category,
                'fsLineItem' => $account->fs_line_item,
                'fsSubLine' => $account->fs_sub_line,
                'normalBalance' => $account->normal_balance,
                'debitMinor' => $net > 0 ? $net : 0,
                'creditMinor' => $net < 0 ? -$net : 0,
                'balanceMinor' => $normalBalanceMinor,
            ];

            if ($rate !== null && $rate > 0) {
                $item['presentationDebitMinor'] = $net > 0 ? (int) round($net / $rate) : 0;
                $item['presentationCreditMinor'] = $net < 0 ? (int) round((-$net) / $rate) : 0;
            }

            $items[] = $item;
            $totalDebit += $item['debitMinor'];
            $totalCredit += $item['creditMinor'];
        }

        return [
            'asOf' => $asOf,
            'functionalCurrency' => 'GHS',
            'presentationCurrency' => $currency,
            'rate' => $rate,
            'rateDate' => $rateDate,
            'items' => $items,
            'totals' => [
                'debitMinor' => $totalDebit,
                'creditMinor' => $totalCredit,
                'balanced' => $totalDebit === $totalCredit,
            ],
        ];
    }
}
