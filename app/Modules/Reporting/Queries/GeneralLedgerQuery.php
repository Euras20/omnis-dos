<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Queries;

use App\Models\Account;
use App\Models\ExchangeRate;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/v1/reports/general-ledger
 *
 * Posted journal lines for one or all accounts between from and to, with
 * opening balance and running balance. Optional presentation currency uses
 * the mid rate on each posting date.
 */
final class GeneralLedgerQuery
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
    ) {
    }

    /**
     * @param  array{from?: ?string, to?: ?string, account?: ?string, currency?: ?string}  $filters
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
        $from = isset($filters['from']) && is_string($filters['from']) && $filters['from'] !== ''
            ? $filters['from']
            : now()->startOfMonth()->toDateString();
        $to = isset($filters['to']) && is_string($filters['to']) && $filters['to'] !== ''
            ? $filters['to']
            : now()->toDateString();
        $accountCode = isset($filters['account']) && is_string($filters['account']) && $filters['account'] !== ''
            ? $filters['account']
            : null;
        $currency = isset($filters['currency']) && is_string($filters['currency']) && $filters['currency'] !== ''
            ? strtoupper($filters['currency'])
            : null;

        $accountQuery = Account::query()
            ->where('company_id', $companyId)
            ->orderBy('code');
        if ($accountCode !== null) {
            $accountQuery->where('code', $accountCode);
        }
        $accounts = $accountQuery->get();
        $accountIds = $accounts->pluck('id')->all();

        if ($accountIds === []) {
            return $this->emptyResponse($from, $to, $accountCode, $currency);
        }

        // Opening balances (posted lines before from)
        $opening = DB::table('journal_line')
            ->select([
                'journal_line.account_id',
                DB::raw('COALESCE(SUM(journal_line.debit_minor - journal_line.credit_minor), 0) as net'),
            ])
            ->join('journal', 'journal.id', '=', 'journal_line.journal_id')
            ->where('journal_line.company_id', $companyId)
            ->where('journal.status', 'posted')
            ->where('journal.posting_date', '<', $from)
            ->whereIn('journal_line.account_id', $accountIds)
            ->groupBy('journal_line.account_id')
            ->get()
            ->keyBy('account_id');

        // Period lines
        $lines = DB::table('journal_line')
            ->select([
                'journal_line.account_id',
                'journal_line.debit_minor',
                'journal_line.credit_minor',
                'journal_line.memo',
                'journal.posting_date',
                'journal.journal_no',
                'journal.source_type',
                'journal.source_id',
                'journal.description',
                'journal.posted_by',
            ])
            ->join('journal', 'journal.id', '=', 'journal_line.journal_id')
            ->where('journal_line.company_id', $companyId)
            ->where('journal.status', 'posted')
            ->whereBetween('journal.posting_date', [$from, $to])
            ->whereIn('journal_line.account_id', $accountIds)
            ->orderBy('journal.posting_date')
            ->orderBy('journal.journal_no')
            ->orderBy('journal_line.line_no')
            ->get()
            ->groupBy('account_id');

        $rates = $this->loadRates($companyId, $currency, $from, $to);

        $sections = [];
        foreach ($accounts as $account) {
            $openNet = (int) ($opening->get($account->id)->net ?? 0);
            $running = $openNet;
            $periodLines = $lines->get($account->id) ?? collect();

            if ($openNet === 0 && $periodLines->isEmpty()) {
                continue;
            }

            $entries = [];
            foreach ($periodLines as $line) {
                $debit = (int) $line->debit_minor;
                $credit = (int) $line->credit_minor;
                $running += $debit - $credit;

                $entry = [
                    'date' => (string) $line->posting_date,
                    'journalNo' => $line->journal_no,
                    'sourceType' => $line->source_type,
                    'sourceId' => $line->source_id,
                    'description' => $line->description,
                    'memo' => $line->memo,
                    'debitMinor' => $debit,
                    'creditMinor' => $credit,
                    'balanceMinor' => $running,
                    'postedBy' => $line->posted_by,
                ];

                if ($currency !== null && $currency !== 'GHS') {
                    $rate = $rates[$line->posting_date] ?? null;
                    $entry['rate'] = $rate;
                    $entry['presentationDebitMinor'] = $rate !== null && $rate > 0 ? (int) round($debit / $rate) : null;
                    $entry['presentationCreditMinor'] = $rate !== null && $rate > 0 ? (int) round($credit / $rate) : null;
                }

                $entries[] = $entry;
            }

            $sections[] = [
                'accountCode' => $account->code,
                'accountName' => $account->name,
                'category' => $account->category,
                'fsLineItem' => $account->fs_line_item,
                'fsSubLine' => $account->fs_sub_line,
                'normalBalance' => $account->normal_balance,
                'openingMinor' => $openNet,
                'closingMinor' => $running,
                'entries' => $entries,
            ];
        }

        return [
            'from' => $from,
            'to' => $to,
            'account' => $accountCode,
            'functionalCurrency' => 'GHS',
            'presentationCurrency' => $currency,
            'sections' => $sections,
        ];
    }

    /**
     * @return array<string, float>
     */
    private function loadRates(string $companyId, ?string $currency, string $from, string $to): array
    {
        if ($currency === null || $currency === 'GHS') {
            return [];
        }

        // Load all rates up to $to so we can pick the latest on or before each posting date.
        $rows = ExchangeRate::query()
            ->where('company_id', $companyId)
            ->where('currency', $currency)
            ->where('rate_date', '<=', $to)
            ->orderBy('rate_date')
            ->get(['rate_date', 'mid_rate']);

        $byDate = [];
        $current = null;
        foreach ($rows as $row) {
            $current = (float) $row->mid_rate;
            $byDate[$row->rate_date->format('Y-m-d')] = $current;
        }

        // Fill every date in the range with the latest known rate on or before it.
        $filled = [];
        $cursor = $from;
        $last = null;
        $allDates = array_keys($byDate);
        sort($allDates);
        $idx = 0;
        while ($cursor <= $to) {
            while ($idx < count($allDates) && $allDates[$idx] <= $cursor) {
                $last = $byDate[$allDates[$idx]];
                $idx++;
            }
            if ($last !== null) {
                $filled[$cursor] = $last;
            }
            $cursor = date('Y-m-d', strtotime($cursor.' +1 day'));
        }

        return $filled;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyResponse(string $from, string $to, ?string $account, ?string $currency): array
    {
        return [
            'from' => $from,
            'to' => $to,
            'account' => $account,
            'functionalCurrency' => 'GHS',
            'presentationCurrency' => $currency,
            'sections' => [],
        ];
    }
}
