<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Services;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\ConflictException;
use App\Exceptions\NotFoundException;
use App\Exceptions\UnbalancedJournalException;
use App\Models\Account;
use App\Models\Journal;
use App\Models\JournalLine;
use App\Models\NumberSequence;
use App\Modules\Ledger\Domain\JournalStatus;
use App\Modules\Ledger\Domain\PostingKey;
use App\Modules\Ledger\Domain\PostingLine;
use App\Modules\Ledger\Domain\PostingRequest;
use App\Shared\Audit\AuditWriter;
use App\Shared\PeriodLock\PeriodLockChecker;
use App\Shared\Support\Uuid;
use Illuminate\Support\Facades\DB;

/**
 * Sole writer of journal / journal_line.
 *
 * Other modules call post() inside their own transaction.
 * Enforces: period lock, balanced lines, posting-key uniqueness, account existence.
 */
final class PostingService
{
    public function __construct(
        private readonly PeriodLockChecker $periodLockChecker,
        private readonly AuditWriter $auditWriter,
    ) {
    }

    /**
     * Create a posted journal and return its id.
     *
     * Must be called inside an open DB transaction owned by the caller.
     *
     * @throws UnbalancedJournalException
     * @throws ConflictException  when posting_key already used
     * @throws BusinessRuleException
     */
    public function post(PostingRequest $request): string
    {
        $this->periodLockChecker->assertOpen($request->postingDate);

        $this->assertBalanced($request->lines);
        $this->assertAccountsExist($request->companyId, $request->lines);

        $postingKey = $request->postingKey->value();

        $exists = Journal::query()
            ->where('company_id', $request->companyId)
            ->where('posting_key', $postingKey)
            ->exists();

        if ($exists) {
            throw new ConflictException("A journal with posting key '{$postingKey}' already exists.");
        }

        $journalId = Uuid::v7();
        $journalNo = $this->nextJournalNumber($request->companyId, $request->postingDate);

        // Insert as draft first so journal_line_immutable allows line inserts,
        // then flip to posted (balanced check is DEFERRABLE at commit).
        $journal = new Journal;
        $journal->id = $journalId;
        $journal->company_id = $request->companyId;
        $journal->journal_no = $journalNo;
        $journal->posting_date = $request->postingDate;
        $journal->description = $request->description;
        $journal->source_type = $request->sourceType;
        $journal->source_id = $request->sourceId;
        $journal->event_type = $request->eventType;
        $journal->posting_key = $postingKey;
        $journal->status = JournalStatus::DRAFT;
        $journal->prepared_by = $request->preparedBy ?? $request->actorId;
        $journal->posted_by = null;
        $journal->posted_at = null;
        $journal->currency = $request->currency;
        $journal->request_id = $request->requestId;
        $journal->save();

        $lineNo = 1;
        foreach ($request->lines as $line) {
            /** @var PostingLine $line */
            $row = new JournalLine;
            $row->company_id = $request->companyId;
            $row->journal_id = $journalId;
            $row->line_no = $lineNo++;
            $row->account_id = $line->accountId;
            $row->debit_minor = $line->debitMinor;
            $row->credit_minor = $line->creditMinor;
            $row->party_id = $line->partyId;
            $row->memo = $line->memo;
            $row->dimension1 = $line->dimension1;
            $row->dimension2 = $line->dimension2;
            $row->save();
        }

        $journal->status = JournalStatus::POSTED;
        $journal->posted_by = $request->actorId;
        $journal->posted_at = now();
        $journal->save();

        $this->auditWriter->write(
            action: 'journal.posted',
            entityType: 'journal',
            entityId: $journalId,
            detail: [
                'journalNo' => $journalNo,
                'postingKey' => $postingKey,
                'sourceType' => $request->sourceType,
                'sourceId' => $request->sourceId,
                'postingDate' => $request->postingDate,
                'lineCount' => count($request->lines),
            ],
            actorId: $request->actorId,
        );

        return $journalId;
    }

    /**
     * Create a draft manual journal (not posted).
     *
     * @param  list<PostingLine>  $lines
     */
    public function createDraft(
        string $companyId,
        string $postingDate,
        string $description,
        array $lines,
        string $actorId,
        ?string $requestId = null,
        string $currency = 'GHS',
    ): string {
        if (count($lines) < 2) {
            throw new BusinessRuleException('A journal must have at least two lines.');
        }

        $this->assertBalanced($lines);
        $this->assertAccountsExist($companyId, $lines);

        $journalId = Uuid::v7();
        // Draft uses a provisional posting_key so uniqueness is reserved early.
        $postingKey = PostingKey::forManual($journalId)->value();

        $journal = new Journal;
        $journal->id = $journalId;
        $journal->company_id = $companyId;
        $journal->journal_no = null;
        $journal->posting_date = $postingDate;
        $journal->description = $description;
        $journal->source_type = 'manual';
        $journal->source_id = $journalId;
        $journal->event_type = 'post';
        $journal->posting_key = $postingKey;
        $journal->status = JournalStatus::DRAFT;
        $journal->prepared_by = $actorId;
        $journal->currency = $currency;
        $journal->request_id = $requestId;
        $journal->save();

        $lineNo = 1;
        foreach ($lines as $line) {
            $row = new JournalLine;
            $row->company_id = $companyId;
            $row->journal_id = $journalId;
            $row->line_no = $lineNo++;
            $row->account_id = $line->accountId;
            $row->debit_minor = $line->debitMinor;
            $row->credit_minor = $line->creditMinor;
            $row->party_id = $line->partyId;
            $row->memo = $line->memo;
            $row->dimension1 = $line->dimension1;
            $row->dimension2 = $line->dimension2;
            $row->save();
        }

        $this->auditWriter->write(
            action: 'journal.draft_created',
            entityType: 'journal',
            entityId: $journalId,
            detail: [
                'postingDate' => $postingDate,
                'lineCount' => count($lines),
            ],
            actorId: $actorId,
        );

        return $journalId;
    }

    /**
     * Promote a draft manual journal to posted (SoD: poster ≠ preparer).
     */
    public function postDraft(string $journalId, string $actorId, ?string $requestId = null): string
    {
        $journal = Journal::query()->where('id', $journalId)->first();
        if ($journal === null) {
            throw new NotFoundException('Journal not found.');
        }

        if ($journal->status !== JournalStatus::DRAFT) {
            throw new BusinessRuleException('Only draft journals can be posted this way.');
        }

        if ($journal->source_type !== 'manual') {
            throw new BusinessRuleException('Only manual journals are posted via this path.');
        }

        if ($journal->prepared_by !== null && $journal->prepared_by === $actorId) {
            throw new \App\Exceptions\SodViolationException(
                'The preparer of a journal cannot post it (segregation of duties).'
            );
        }

        $this->periodLockChecker->assertOpen($journal->posting_date->format('Y-m-d'));

        $lines = JournalLine::query()
            ->where('journal_id', $journalId)
            ->orderBy('line_no')
            ->get();

        if ($lines->count() < 2) {
            throw new UnbalancedJournalException('Journal must have at least two lines.');
        }

        $debit = (int) $lines->sum('debit_minor');
        $credit = (int) $lines->sum('credit_minor');
        if ($debit !== $credit || $debit === 0) {
            throw UnbalancedJournalException::withTotals($debit, $credit);
        }

        $journalNo = $this->nextJournalNumber($journal->company_id, $journal->posting_date->format('Y-m-d'));

        $journal->journal_no = $journalNo;
        $journal->status = JournalStatus::POSTED;
        $journal->posted_by = $actorId;
        $journal->posted_at = now();
        if ($requestId !== null) {
            $journal->request_id = $requestId;
        }
        $journal->save();

        $this->auditWriter->write(
            action: 'journal.posted',
            entityType: 'journal',
            entityId: $journalId,
            detail: [
                'journalNo' => $journalNo,
                'postingKey' => $journal->posting_key,
                'sourceType' => 'manual',
                'postingDate' => $journal->posting_date->format('Y-m-d'),
            ],
            actorId: $actorId,
        );

        return $journalId;
    }

    /**
     * Reverse a posted manual journal (creates a reversing journal and links both).
     */
    public function reverseManual(string $journalId, string $actorId, ?string $requestId = null): string
    {
        $original = Journal::query()->where('id', $journalId)->with('lines')->first();
        if ($original === null) {
            throw new NotFoundException('Journal not found.');
        }

        if ($original->status !== JournalStatus::POSTED) {
            throw new BusinessRuleException('Only posted journals can be reversed.');
        }

        if ($original->source_type !== 'manual') {
            throw new BusinessRuleException(
                'Only manual journals may be reversed directly. Source documents are corrected through their own modules.'
            );
        }

        if ($original->reversed_by_journal_id !== null) {
            throw new BusinessRuleException('This journal has already been reversed.');
        }

        $this->periodLockChecker->assertOpen($original->posting_date->format('Y-m-d'));

        $reverseLines = [];
        foreach ($original->lines as $line) {
            $reverseLines[] = new PostingLine(
                accountId: $line->account_id,
                debitMinor: (int) $line->credit_minor,
                creditMinor: (int) $line->debit_minor,
                partyId: $line->party_id,
                memo: $line->memo !== null ? 'Reversal: '.$line->memo : 'Reversal',
                dimension1: $line->dimension1,
                dimension2: $line->dimension2,
            );
        }

        $reverseKey = PostingKey::forReverse($journalId);
        $request = new PostingRequest(
            companyId: $original->company_id,
            postingDate: $original->posting_date->format('Y-m-d'),
            postingKey: $reverseKey,
            sourceType: 'reverse',
            sourceId: $journalId,
            eventType: 'reverse',
            description: 'Reversal of '.$original->journal_no,
            lines: $reverseLines,
            actorId: $actorId,
            requestId: $requestId,
            currency: $original->currency,
            preparedBy: $actorId,
        );

        $reverseId = $this->post($request);

        // Link original → reverse (allowed by immutability trigger)
        $original->reversed_by_journal_id = $reverseId;
        $original->status = JournalStatus::REVERSED;
        $original->save();

        // Link reverse → original
        Journal::query()->where('id', $reverseId)->update([
            'reverses_journal_id' => $journalId,
        ]);

        $this->auditWriter->write(
            action: 'journal.reversed',
            entityType: 'journal',
            entityId: $journalId,
            detail: [
                'reversedByJournalId' => $reverseId,
                'originalJournalNo' => $original->journal_no,
            ],
            actorId: $actorId,
        );

        return $reverseId;
    }

    /**
     * @param  list<PostingLine>  $lines
     */
    private function assertBalanced(array $lines): void
    {
        if (count($lines) < 2) {
            throw new UnbalancedJournalException('Journal must have at least two lines.');
        }

        $debit = 0;
        $credit = 0;
        foreach ($lines as $line) {
            $debit += $line->debitMinor;
            $credit += $line->creditMinor;
        }

        if ($debit !== $credit || $debit === 0) {
            throw UnbalancedJournalException::withTotals($debit, $credit);
        }
    }

    /**
     * @param  list<PostingLine>  $lines
     */
    private function assertAccountsExist(string $companyId, array $lines): void
    {
        $ids = array_unique(array_map(fn (PostingLine $l) => $l->accountId, $lines));

        $found = Account::query()
            ->where('company_id', $companyId)
            ->whereIn('id', $ids)
            ->where('is_active', true)
            ->pluck('id')
            ->all();

        $missing = array_diff($ids, $found);
        if ($missing !== []) {
            throw new BusinessRuleException(
                'Unknown or inactive account(s): '.implode(', ', $missing)
            );
        }
    }

    private function nextJournalNumber(string $companyId, string $postingDate): string
    {
        $year = (int) substr($postingDate, 0, 4);

        // Ensure sequence row exists
        $seq = NumberSequence::query()
            ->where('company_id', $companyId)
            ->where('doc_type', 'journal')
            ->where('fiscal_year', $year)
            ->lockForUpdate()
            ->first();

        if ($seq === null) {
            $seq = new NumberSequence;
            $seq->id = Uuid::v7();
            $seq->company_id = $companyId;
            $seq->doc_type = 'journal';
            $seq->fiscal_year = $year;
            $seq->next_value = 1;
            $seq->prefix = 'JV';
            $seq->pad_length = 5;
            $seq->save();
            $seq = NumberSequence::query()
                ->where('id', $seq->id)
                ->lockForUpdate()
                ->first();
        }

        $value = (int) $seq->next_value;
        $seq->next_value = $value + 1;
        $seq->save();

        $pad = max(1, (int) $seq->pad_length);
        $prefix = $seq->prefix ?? 'JV';

        return sprintf('%s-%d-%s', $prefix, $year, str_pad((string) $value, $pad, '0', STR_PAD_LEFT));
    }
}
