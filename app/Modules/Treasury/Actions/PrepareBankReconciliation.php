<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\NotFoundException;
use App\Models\Account;
use App\Models\BankReconciliation;
use App\Modules\Treasury\Queries\BankReconciliationWorkbenchQuery;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use App\Shared\Support\Uuid;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/bank/reconciliations — prepare a reconciliation snapshot.
 * Difference must be zero. Matching records only; no second posting.
 */
final class PrepareBankReconciliation
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly AuditWriter $auditWriter,
        private readonly BankReconciliationWorkbenchQuery $workbench,
    ) {
    }

    /**
     * @param  array{bankAccountId?: ?string, statementDate?: ?string, statementClosingMinor?: mixed}  $data
     * @return array<string, mixed>
     */
    public function execute(array $data): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'bank.match');

        $companyId = $this->companyContext->companyId();
        $bankAccountId = (string) ($data['bankAccountId'] ?? '');
        $statementDate = (string) ($data['statementDate'] ?? '');
        $closing = isset($data['statementClosingMinor']) ? (int) $data['statementClosingMinor'] : null;

        if ($bankAccountId === '') {
            throw new BusinessRuleException('bankAccountId is required.');
        }
        if ($statementDate === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $statementDate)) {
            throw new BusinessRuleException('statementDate must be YYYY-MM-DD.');
        }
        if ($closing === null) {
            throw new BusinessRuleException('statementClosingMinor is required.');
        }

        $account = Account::query()->where('company_id', $companyId)->where('id', $bankAccountId)->first();
        if (! $account) {
            throw new NotFoundException('Bank account not found.');
        }

        $wb = $this->workbench->execute([
            'bankAccountId' => $bankAccountId,
            'statementDate' => $statementDate,
            'statementClosingMinor' => $closing,
        ]);

        if (($wb['differenceMinor'] ?? null) !== 0) {
            throw new BusinessRuleException('Difference must be zero to prepare a reconciliation.');
        }

        return DB::transaction(function () use ($companyId, $actorId, $bankAccountId, $statementDate, $closing, $wb) {
            $id = Uuid::v7();
            $recon = BankReconciliation::query()->create([
                'id' => $id,
                'company_id' => $companyId,
                'bank_account_id' => $bankAccountId,
                'statement_date' => $statementDate,
                'statement_closing_minor' => $closing,
                'book_balance_minor' => (int) $wb['bookBalanceMinor'],
                'difference_minor' => 0,
                'status' => 'prepared',
                'prepared_by' => $actorId,
                'prepared_at' => now(),
                'snapshot' => [
                    'outstanding' => $wb['outstanding'],
                    'unmatchedLines' => $wb['unmatchedLines'],
                    'adjustedBookMinor' => $wb['adjustedBookMinor'],
                ],
                'request_id' => $this->companyContext->requestId(),
            ]);

            $this->auditWriter->write(
                'bank_reconciliation.prepared',
                'bank_reconciliation',
                $id,
                ['statementDate' => $statementDate, 'closingMinor' => $closing],
                $actorId,
            );

            return [
                'id' => $recon->id,
                'status' => 'prepared',
                'statementDate' => $statementDate,
                'differenceMinor' => 0,
                'preparedAt' => $recon->prepared_at?->toIso8601String(),
            ];
        });
    }
}
