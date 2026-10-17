<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Queries;

use App\Exceptions\NotFoundException;
use App\Models\Account;
use App\Models\BankStatementLine;
use App\Models\Journal;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/v1/bank/reconciliation — workbench for a bank account, statement date and closing balance.
 *
 * Returns book balance, outstanding journals (unmatched), unmatched statement lines, adjusted book and difference.
 * Matching records only; no posting.
 */
final class BankReconciliationWorkbenchQuery
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
    ) {
    }

    /**
     * @param  array{bankAccountId?: ?string, statementDate?: ?string, statementClosingMinor?: mixed}  $params
     * @return array<string, mixed>
     */
    public function execute(array $params): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'bank.view');

        $companyId = $this->companyContext->companyId();
        $bankAccountId = (string) ($params['bankAccountId'] ?? '');
        $statementDate = (string) ($params['statementDate'] ?? '');
        $closing = isset($params['statementClosingMinor']) ? (int) $params['statementClosingMinor'] : null;

        if ($bankAccountId === '') {
            throw new NotFoundException('bankAccountId is required.');
        }

        $account = Account::query()->where('company_id', $companyId)->where('id', $bankAccountId)->first();
        if (! $account) {
            throw new NotFoundException('Bank account not found.');
        }

        // Book balance as of statement date (or today if not given): sum(debit - credit) on posted journals.
        $asOf = $statementDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $statementDate) ? $statementDate : now()->toDateString();

        $bookBalance = (int) DB::table('journal_line as jl')
            ->join('journal as j', 'j.id', '=', 'jl.journal_id')
            ->where('jl.company_id', $companyId)
            ->where('jl.account_id', $bankAccountId)
            ->where('j.status', 'posted')
            ->where('j.posting_date', '<=', $asOf)
            ->sum(DB::raw('jl.debit_minor - jl.credit_minor'));

        // Outstanding: posted journals that touch the bank and are not matched to any statement line.
        $outstanding = Journal::query()
            ->where('company_id', $companyId)
            ->where('status', 'posted')
            ->where('posting_date', '<=', $asOf)
            ->whereNotExists(function ($q) use ($companyId) {
                $q->select(DB::raw(1))
                    ->from('bank_statement_line as bsl')
                    ->whereColumn('bsl.matched_journal_id', 'journal.id')
                    ->where('bsl.company_id', $companyId)
                    ->where('bsl.status', 'matched');
            })
            ->whereExists(function ($q) use ($companyId, $bankAccountId) {
                $q->select(DB::raw(1))
                    ->from('journal_line as jl')
                    ->whereColumn('jl.journal_id', 'journal.id')
                    ->where('jl.company_id', $companyId)
                    ->where('jl.account_id', $bankAccountId);
            })
            ->orderBy('posting_date')
            ->limit(200)
            ->get()
            ->map(function (Journal $j) use ($companyId, $bankAccountId) {
                $effect = (int) DB::table('journal_line')
                    ->where('company_id', $companyId)
                    ->where('journal_id', $j->id)
                    ->where('account_id', $bankAccountId)
                    ->sum(DB::raw('debit_minor - credit_minor'));

                return [
                    'journalId' => $j->id,
                    'journalNo' => $j->journal_no,
                    'postingDate' => $j->posting_date?->toDateString(),
                    'description' => $j->description,
                    'bankEffectMinor' => $effect,
                ];
            })
            ->filter(fn ($x) => $x['bankEffectMinor'] !== 0)
            ->values()
            ->all();

        $outstandingSum = array_sum(array_column($outstanding, 'bankEffectMinor'));

        $unmatchedLines = BankStatementLine::query()
            ->where('company_id', $companyId)
            ->where('bank_account_id', $bankAccountId)
            ->where('status', 'unmatched')
            ->orderBy('txn_date')
            ->limit(200)
            ->get()
            ->map(function (BankStatementLine $l) {
                $signed = $l->direction === 'credit' ? (int) $l->amount_minor : -(int) $l->amount_minor;

                return [
                    'id' => $l->id,
                    'txnDate' => $l->txn_date?->toDateString(),
                    'reference' => $l->reference,
                    'description' => $l->description,
                    'signedAmountMinor' => $signed,
                ];
            })
            ->all();

        $unmatchedSum = array_sum(array_column($unmatchedLines, 'signedAmountMinor'));

        // Adjusted book = book - outstanding effects + unmatched statement amounts (frontend convention).
        $adjusted = $bookBalance - $outstandingSum + $unmatchedSum;
        $difference = $closing !== null ? $adjusted - $closing : null;

        return [
            'bankAccountId' => $bankAccountId,
            'statementDate' => $asOf,
            'bookBalanceMinor' => $bookBalance,
            'outstanding' => $outstanding,
            'outstandingSumMinor' => $outstandingSum,
            'unmatchedLines' => $unmatchedLines,
            'unmatchedSumMinor' => $unmatchedSum,
            'adjustedBookMinor' => $adjusted,
            'statementClosingMinor' => $closing,
            'differenceMinor' => $difference,
            'canPrepare' => $difference === 0,
        ];
    }
}
