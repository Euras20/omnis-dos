<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\NotFoundException;
use App\Models\BankReconciliation;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/bank/reconciliations/{id}/approve — approve a prepared reconciliation.
 * Approver must differ from preparer. Difference must remain zero (DB trigger).
 */
final class ApproveBankReconciliation
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
    public function execute(string $id): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'bank.approve');

        $companyId = $this->companyContext->companyId();

        return DB::transaction(function () use ($companyId, $actorId, $id) {
            $recon = BankReconciliation::query()
                ->where('company_id', $companyId)
                ->where('id', $id)
                ->lockForUpdate()
                ->first();
            if (! $recon) {
                throw new NotFoundException('Reconciliation not found.');
            }
            if ($recon->status !== 'prepared') {
                throw new BusinessRuleException('Only a prepared reconciliation can be approved.');
            }
            if ((int) $recon->difference_minor !== 0) {
                throw new BusinessRuleException('Difference must be zero to approve.');
            }
            if ($recon->prepared_by !== null && $recon->prepared_by === $actorId) {
                throw new BusinessRuleException('Approver must differ from preparer.');
            }

            $recon->update([
                'status' => 'approved',
                'approved_by' => $actorId,
                'approved_at' => now(),
            ]);

            $this->auditWriter->write(
                'bank_reconciliation.approved',
                'bank_reconciliation',
                $id,
                [],
                $actorId,
            );

            return [
                'id' => $recon->id,
                'status' => 'approved',
                'approvedAt' => $recon->approved_at?->toIso8601String(),
            ];
        });
    }
}
