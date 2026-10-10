<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Actions;

use App\Models\Journal;
use App\Modules\Ledger\Services\PostingService;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/journals/{id}/reverse — reverse a posted MANUAL journal.
 */
final class ReverseJournal
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly PostingService $postingService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(string $id): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'ledger.journal.post');

        $requestId = $this->companyContext->requestId();

        return DB::transaction(function () use ($id, $actorId, $requestId) {
            $reverseId = $this->postingService->reverseManual($id, $actorId, $requestId);

            $journal = Journal::query()->with('lines.account')->findOrFail($reverseId);

            return $this->toArray($journal);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(Journal $journal): array
    {
        $lines = [];
        foreach ($journal->lines as $line) {
            $lines[] = [
                'lineNo' => $line->line_no,
                'accountId' => $line->account_id,
                'accountCode' => $line->account?->code,
                'accountName' => $line->account?->name,
                'debitMinor' => (int) $line->debit_minor,
                'creditMinor' => (int) $line->credit_minor,
                'partyId' => $line->party_id,
                'memo' => $line->memo,
            ];
        }

        return [
            'id' => $journal->id,
            'journalNo' => $journal->journal_no,
            'postingDate' => $journal->posting_date?->format('Y-m-d'),
            'description' => $journal->description,
            'sourceType' => $journal->source_type,
            'status' => $journal->status,
            'currency' => $journal->currency,
            'preparedBy' => $journal->prepared_by,
            'postedBy' => $journal->posted_by,
            'postedAt' => $journal->posted_at?->toIso8601String(),
            'reversesJournalId' => $journal->reverses_journal_id,
            'lines' => $lines,
        ];
    }
}
