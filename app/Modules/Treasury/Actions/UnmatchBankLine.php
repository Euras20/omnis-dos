<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\NotFoundException;
use App\Models\BankStatementLine;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/bank/lines/{id}/unmatch — undo a match (before the period is reconciled).
 */
final class UnmatchBankLine
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly AuditWriter $auditWriter,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(int|string $lineId): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'bank.match');

        $companyId = $this->companyContext->companyId();

        return DB::transaction(function () use ($companyId, $actorId, $lineId) {
            $line = BankStatementLine::query()
                ->where('company_id', $companyId)
                ->where('id', $lineId)
                ->lockForUpdate()
                ->first();
            if (! $line) {
                throw new NotFoundException('Statement line not found.');
            }
            if ($line->status !== 'matched' && $line->status !== 'excluded') {
                throw new BusinessRuleException('Statement line is not matched or excluded.');
            }

            $previousJournal = $line->matched_journal_id;

            $line->update([
                'status' => 'unmatched',
                'matched_journal_id' => null,
                'matched_by' => null,
                'matched_at' => null,
                'exclude_reason' => null,
            ]);

            $this->auditWriter->write(
                'bank_statement_line.unmatched',
                'bank_statement_line',
                (string) $line->id,
                ['previousJournalId' => $previousJournal],
                $actorId,
            );

            return [
                'id' => $line->id,
                'status' => 'unmatched',
            ];
        });
    }
}
