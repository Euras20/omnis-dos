<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Queries;

use App\Exceptions\NotFoundException;
use App\Models\BankStatementLine;
use App\Models\Journal;
use App\Models\JournalLine;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/v1/bank/lines/{id}/candidates — suggested posted journals that could match the statement line.
 *
 * Criteria: same absolute amount as the signed bank effect, posting date within ±15 days, journal not already matched.
 */
final class GetBankLineCandidatesQuery
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
    ) {
    }

    /**
     * @return array{lineId: int|string, candidates: list<array<string, mixed>>}
     */
    public function execute(int|string $lineId): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'bank.view');

        $companyId = $this->companyContext->companyId();

        $line = BankStatementLine::query()
            ->where('company_id', $companyId)
            ->where('id', $lineId)
            ->first();
        if (! $line) {
            throw new NotFoundException('Statement line not found.');
        }

        $signed = $line->direction === 'credit' ? (int) $line->amount_minor : -(int) $line->amount_minor;
        $targetAbs = abs($signed);
        $from = $line->txn_date?->copy()->subDays(15)->toDateString();
        $to = $line->txn_date?->copy()->addDays(15)->toDateString();

        // Journals that touch this bank account, posted, not already matched, net effect equals signed amount.
        $candidates = Journal::query()
            ->where('company_id', $companyId)
            ->where('status', 'posted')
            ->whereBetween('posting_date', [$from, $to])
            ->whereNotExists(function ($q) use ($companyId) {
                $q->select(DB::raw(1))
                    ->from('bank_statement_line as bsl')
                    ->whereColumn('bsl.matched_journal_id', 'journal.id')
                    ->where('bsl.company_id', $companyId)
                    ->where('bsl.status', 'matched');
            })
            ->whereExists(function ($q) use ($companyId, $line, $signed) {
                $q->select(DB::raw(1))
                    ->from('journal_line as jl')
                    ->whereColumn('jl.journal_id', 'journal.id')
                    ->where('jl.company_id', $companyId)
                    ->where('jl.account_id', $line->bank_account_id)
                    ->groupBy('jl.journal_id')
                    ->havingRaw('SUM(jl.debit_minor - jl.credit_minor) = ?', [$signed]);
            })
            ->orderBy('posting_date')
            ->limit(20)
            ->get();

        $items = $candidates->map(function (Journal $j) use ($line) {
            $effect = (int) JournalLine::query()
                ->where('journal_id', $j->id)
                ->where('account_id', $line->bank_account_id)
                ->selectRaw('COALESCE(SUM(debit_minor - credit_minor), 0) as effect')
                ->value('effect');

            return [
                'journalId' => $j->id,
                'journalNo' => $j->journal_no,
                'postingDate' => $j->posting_date?->toDateString(),
                'description' => $j->description,
                'sourceType' => $j->source_type,
                'bankEffectMinor' => $effect,
            ];
        })->all();

        return ['lineId' => $line->id, 'candidates' => $items];
    }
}
