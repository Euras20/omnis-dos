<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\NotFoundException;
use App\Models\Account;
use App\Models\PayrollRun;
use App\Modules\Ledger\Domain\PostingKey;
use App\Modules\Ledger\Domain\PostingLine;
use App\Modules\Ledger\Domain\PostingRequest;
use App\Modules\Ledger\Services\PostingService;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use App\Shared\PeriodLock\PeriodLockChecker;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/payroll/runs/{id}/post — accrual journal once.
 * Dr wages + employer SSF; Cr PAYE, Tier1/2 (SSF employee+employer split), net pay.
 */
final class PostPayrollRun
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly PostingService $postingService,
        private readonly PeriodLockChecker $periodLockChecker,
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
            if ($run->status !== 'approved') {
                throw new BusinessRuleException('Only approved payroll runs can be posted.');
            }

            $postingDate = $run->period_month.'-28';
            $this->periodLockChecker->assertOpen($postingDate);

            $accounts = $this->resolveAccounts($companyId);

            $lines = [];
            // Dr wages (gross)
            $lines[] = new PostingLine(
                accountId: $accounts['wages'],
                debitMinor: (int) $run->gross_total_minor,
                creditMinor: 0,
                memo: 'Payroll gross wages '.$run->period_month,
            );
            // Dr employer SSF
            if ((int) $run->ssf_employer_minor > 0) {
                $lines[] = new PostingLine(
                    accountId: $accounts['ssfEmployer'],
                    debitMinor: (int) $run->ssf_employer_minor,
                    creditMinor: 0,
                    memo: 'Employer SSF '.$run->period_month,
                );
            }
            // Cr PAYE (includes junior overtime tax)
            $payeTotal = (int) $run->paye_minor + (int) $run->ot_tax_total_minor;
            if ($payeTotal > 0) {
                $lines[] = new PostingLine(
                    accountId: $accounts['paye'],
                    debitMinor: 0,
                    creditMinor: $payeTotal,
                    memo: 'PAYE '.$run->period_month,
                );
            }
            // Cr SSF payable (employee + employer)
            $ssfPayable = (int) $run->ssf_employee_minor + (int) $run->ssf_employer_minor;
            if ($ssfPayable > 0) {
                $lines[] = new PostingLine(
                    accountId: $accounts['ssfPayable'],
                    debitMinor: 0,
                    creditMinor: $ssfPayable,
                    memo: 'SSF payable '.$run->period_month,
                );
            }
            // Cr net pay (accrued)
            if ((int) $run->net_total_minor > 0) {
                $lines[] = new PostingLine(
                    accountId: $accounts['netPay'],
                    debitMinor: 0,
                    creditMinor: (int) $run->net_total_minor,
                    memo: 'Net pay accrued '.$run->period_month,
                );
            }

            $postingKey = PostingKey::make('payroll', $runId, 'post');
            $journalId = $this->postingService->post(new PostingRequest(
                companyId: $companyId,
                postingDate: $postingDate,
                postingKey: $postingKey,
                sourceType: 'payroll',
                sourceId: $runId,
                eventType: 'post',
                description: 'Payroll accrual '.$run->period_month,
                lines: $lines,
                actorId: $actorId,
                requestId: $this->companyContext->requestId(),
                currency: 'GHS',
            ));

            $run->status = 'posted';
            $run->journal_id = $journalId;
            $run->posted_by = $actorId;
            $run->posted_at = now();
            $run->save();

            $this->auditWriter->write(
                action: 'payroll_run.posted',
                entityType: 'payroll_run',
                entityId: $runId,
                detail: ['journal_id' => $journalId, 'period_month' => $run->period_month],
            );

            return [
                'id' => $run->id,
                'runNo' => $run->run_no,
                'periodMonth' => $run->period_month,
                'status' => $run->status,
                'journalId' => $journalId,
                'postedAt' => $run->posted_at?->toIso8601String(),
            ];
        });
    }

    /**
     * @return array<string, string>
     */
    private function resolveAccounts(string $companyId): array
    {
        $codes = [
            'wages' => '5100',
            'ssfEmployer' => '5110',
            'paye' => '2200',
            'ssfPayable' => '2210',
            'netPay' => '2220',
        ];
        $out = [];
        foreach ($codes as $key => $code) {
            $acc = Account::query()
                ->where('company_id', $companyId)
                ->where('code', $code)
                ->where('is_active', true)
                ->first();
            if ($acc === null) {
                throw new BusinessRuleException("Required account {$code} not found for payroll posting.");
            }
            $out[$key] = $acc->id;
        }

        return $out;
    }
}
