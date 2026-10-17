<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\NotFoundException;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/payroll/runs/{id}/approve — freeze lines; SoD vs preparer.
 */
final class ApprovePayrollRun
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
    public function execute(string $runId): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'payroll.approve');

        $companyId = $this->companyContext->companyId();

        return DB::transaction(function () use ($companyId, $actorId, $runId) {
            $run = PayrollRun::query()
                ->where('company_id', $companyId)
                ->where('id', $runId)
                ->lockForUpdate()
                ->first();

            if ($run === null) {
                throw new NotFoundException('Payroll run not found.');
            }
            if ($run->status !== 'draft') {
                throw new BusinessRuleException('Only draft payroll runs can be approved.');
            }
            if ($run->prepared_by !== null && $run->prepared_by === $actorId) {
                throw new BusinessRuleException('Approver must differ from preparer (SoD).');
            }
            $exceptions = $run->exceptions ?? [];
            if (is_array($exceptions) && $exceptions !== []) {
                throw new BusinessRuleException('Payroll has unresolved exceptions. Resolve overtime first.');
            }

            PayrollLine::query()
                ->where('payroll_run_id', $runId)
                ->update(['is_frozen' => true]);

            $run->status = 'approved';
            $run->approved_by = $actorId;
            $run->approved_at = now();
            $run->save();

            $this->auditWriter->write(
                action: 'payroll_run.approved',
                entityType: 'payroll_run',
                entityId: $runId,
                detail: ['period_month' => $run->period_month],
            );

            return [
                'id' => $run->id,
                'runNo' => $run->run_no,
                'periodMonth' => $run->period_month,
                'status' => $run->status,
                'approvedAt' => $run->approved_at?->toIso8601String(),
            ];
        });
    }
}
