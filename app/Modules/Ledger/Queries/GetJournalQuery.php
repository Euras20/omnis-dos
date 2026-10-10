<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Queries;

use App\Exceptions\NotFoundException;
use App\Models\Journal;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;

/**
 * GET /api/v1/journals/{id} — journal with lines.
 */
final class GetJournalQuery
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
        $this->permissionChecker->assertCan($actorId, 'ledger.read');

        $companyId = $this->companyContext->companyId();

        $journal = Journal::query()
            ->where('company_id', $companyId)
            ->where('id', $id)
            ->with(['lines.account'])
            ->first();

        if ($journal === null) {
            throw new NotFoundException('Journal not found.');
        }

        $lines = [];
        $totalDebit = 0;
        $totalCredit = 0;
        foreach ($journal->lines as $line) {
            $debit = (int) $line->debit_minor;
            $credit = (int) $line->credit_minor;
            $totalDebit += $debit;
            $totalCredit += $credit;
            $lines[] = [
                'lineNo' => $line->line_no,
                'accountId' => $line->account_id,
                'accountCode' => $line->account?->code,
                'accountName' => $line->account?->name,
                'debitMinor' => $debit,
                'creditMinor' => $credit,
                'partyId' => $line->party_id,
                'memo' => $line->memo,
                'dimension1' => $line->dimension1,
                'dimension2' => $line->dimension2,
            ];
        }

        return [
            'id' => $journal->id,
            'journalNo' => $journal->journal_no,
            'postingDate' => $journal->posting_date?->format('Y-m-d'),
            'description' => $journal->description,
            'sourceType' => $journal->source_type,
            'sourceId' => $journal->source_id,
            'eventType' => $journal->event_type,
            'postingKey' => $journal->posting_key,
            'status' => $journal->status,
            'currency' => $journal->currency,
            'preparedBy' => $journal->prepared_by,
            'postedBy' => $journal->posted_by,
            'postedAt' => $journal->posted_at?->toIso8601String(),
            'reversesJournalId' => $journal->reverses_journal_id,
            'reversedByJournalId' => $journal->reversed_by_journal_id,
            'totalDebitMinor' => $totalDebit,
            'totalCreditMinor' => $totalCredit,
            'lines' => $lines,
        ];
    }
}
