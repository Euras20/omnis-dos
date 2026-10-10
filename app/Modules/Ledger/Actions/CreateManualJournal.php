<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Actions;

use App\Exceptions\BusinessRuleException;
use App\Models\Journal;
use App\Modules\Ledger\Domain\PostingLine;
use App\Modules\Ledger\Services\PostingService;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/journals — create a draft manual journal.
 */
final class CreateManualJournal
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly PostingService $postingService,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function execute(array $data): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'ledger.journal.create');

        $postingDate = (string) ($data['postingDate'] ?? $data['date'] ?? '');
        if ($postingDate === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $postingDate)) {
            throw new BusinessRuleException('postingDate must be YYYY-MM-DD.');
        }

        $description = trim((string) ($data['description'] ?? $data['memo'] ?? ''));
        $rawLines = $data['lines'] ?? [];
        if (! is_array($rawLines) || count($rawLines) < 2) {
            throw new BusinessRuleException('A journal must have at least two lines.');
        }

        $lines = [];
        foreach ($rawLines as $i => $raw) {
            if (! is_array($raw)) {
                throw new BusinessRuleException("Line {$i} is invalid.");
            }
            $accountId = (string) ($raw['accountId'] ?? '');
            if ($accountId === '') {
                throw new BusinessRuleException("Line {$i} requires accountId.");
            }
            $debit = (int) ($raw['debitMinor'] ?? $raw['debit'] ?? 0);
            $credit = (int) ($raw['creditMinor'] ?? $raw['credit'] ?? 0);
            $lines[] = new PostingLine(
                accountId: $accountId,
                debitMinor: $debit,
                creditMinor: $credit,
                partyId: isset($raw['partyId']) ? (string) $raw['partyId'] : null,
                memo: isset($raw['memo']) ? (string) $raw['memo'] : null,
                dimension1: isset($raw['dimension1']) ? (string) $raw['dimension1'] : null,
                dimension2: isset($raw['dimension2']) ? (string) $raw['dimension2'] : null,
            );
        }

        $companyId = $this->companyContext->companyId();
        $currency = strtoupper((string) ($data['currency'] ?? 'GHS'));
        $requestId = $this->companyContext->requestId();

        return DB::transaction(function () use (
            $companyId,
            $postingDate,
            $description,
            $lines,
            $actorId,
            $requestId,
            $currency
        ) {
            $journalId = $this->postingService->createDraft(
                companyId: $companyId,
                postingDate: $postingDate,
                description: $description,
                lines: $lines,
                actorId: $actorId,
                requestId: $requestId,
                currency: $currency,
            );

            $journal = Journal::query()->with('lines.account')->findOrFail($journalId);

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
            'lines' => $lines,
        ];
    }
}
