<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\NotFoundException;
use App\Models\BankStatementLine;
use App\Models\Journal;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/bank/lines/{id}/match — match a statement line to a posted journal.
 * Matching records only; no second bank posting.
 */
final class MatchBankLine
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly AuditWriter $auditWriter,
    ) {
    }

    /**
     * @param  array{journalId?: ?string}  $data
     * @return array<string, mixed>
     */
    public function execute(int|string $lineId, array $data): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'bank.match');

        $companyId = $this->companyContext->companyId();
        $journalId = (string) ($data['journalId'] ?? '');
        if ($journalId === '') {
            throw new BusinessRuleException('journalId is required.');
        }

        return DB::transaction(function () use ($companyId, $actorId, $lineId, $journalId) {
            $line = BankStatementLine::query()
                ->where('company_id', $companyId)
                ->where('id', $lineId)
                ->lockForUpdate()
                ->first();
            if (! $line) {
                throw new NotFoundException('Statement line not found.');
            }
            if ($line->status === 'matched') {
                throw new BusinessRuleException('Statement line is already matched.');
            }
            if ($line->status === 'excluded') {
                throw new BusinessRuleException('Cannot match an excluded statement line.');
            }

            $journal = Journal::query()
                ->where('company_id', $companyId)
                ->where('id', $journalId)
                ->where('status', 'posted')
                ->first();
            if (! $journal) {
                throw new NotFoundException('Posted journal not found.');
            }

            $already = BankStatementLine::query()
                ->where('company_id', $companyId)
                ->where('matched_journal_id', $journalId)
                ->where('status', 'matched')
                ->exists();
            if ($already) {
                throw new BusinessRuleException('This journal is already matched to another statement line.');
            }

            $line->update([
                'status' => 'matched',
                'matched_journal_id' => $journalId,
                'matched_by' => $actorId,
                'matched_at' => now(),
            ]);

            $this->auditWriter->write(
                'bank_statement_line.matched',
                'bank_statement_line',
                (string) $line->id,
                ['journalId' => $journalId],
                $actorId,
            );

            return [
                'id' => $line->id,
                'status' => 'matched',
                'matchedJournalId' => $journalId,
                'matchedAt' => $line->matched_at?->toIso8601String(),
            ];
        });
    }
}
