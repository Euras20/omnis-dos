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
 * POST /api/v1/bank/lines/{id}/exclude — exclude a statement line with a reason.
 */
final class ExcludeBankLine
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly AuditWriter $auditWriter,
    ) {
    }

    /**
     * @param  array{reason?: ?string}  $data
     * @return array<string, mixed>
     */
    public function execute(int|string $lineId, array $data): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'bank.match');

        $companyId = $this->companyContext->companyId();
        $reason = trim((string) ($data['reason'] ?? ''));
        if ($reason === '') {
            throw new BusinessRuleException('reason is required.');
        }

        return DB::transaction(function () use ($companyId, $actorId, $lineId, $reason) {
            $line = BankStatementLine::query()
                ->where('company_id', $companyId)
                ->where('id', $lineId)
                ->lockForUpdate()
                ->first();
            if (! $line) {
                throw new NotFoundException('Statement line not found.');
            }
            if ($line->status === 'matched') {
                throw new BusinessRuleException('Cannot exclude a matched statement line. Unmatch it first.');
            }
            if ($line->status === 'excluded') {
                throw new BusinessRuleException('Statement line is already excluded.');
            }

            $line->update([
                'status' => 'excluded',
                'exclude_reason' => $reason,
                'matched_by' => $actorId,
                'matched_at' => now(),
            ]);

            $this->auditWriter->write(
                'bank_statement_line.excluded',
                'bank_statement_line',
                (string) $line->id,
                ['reason' => $reason],
                $actorId,
            );

            return [
                'id' => $line->id,
                'status' => 'excluded',
                'excludeReason' => $reason,
            ];
        });
    }
}
