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
 * POST /api/v1/payroll/runs/{id}/pay — confirm net-pay batch with bank reference.
 */
final class PayPayrollRun
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
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function execute(string $runId, array $data): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'payroll.approve');

        $companyId = $this->companyContext->companyId();
        $bankRef = trim((string) ($data['bankRef'] ?? $data['bank_ref'] ?? ''));
        $payDate = (string) ($data['payDate'] ?? $data['pay_date'] ?? date('Y-m-d'));

        if ($bankRef === '') {
            throw new BusinessRuleException('bankRef is required.');
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $payDate)) {
            throw new BusinessRuleException('payDate must be YYYY-MM-DD.');
        }

        return DB::transaction(function () use ($companyId, $actorId, $runId, $bankRef, $payDate) {
            $run = PayrollRun::query()
                ->where('company_id', $companyId)
                ->where('id', $runId)
                ->lockForUpdate()
                ->first();

            if ($run === null) {
                throw new NotFoundException('Payroll run not found.');
            }
            if ($run->status !== 'posted') {
                throw new BusinessRuleException('Only posted payroll runs can be paid.');
            }

            $this->periodLockChecker->assertOpen($payDate);

            $bank = Account::query()
                ->where('company_id', $companyId)
                ->where('code', '1100')
                ->where('is_active', true)
                ->first();
            $netPay = Account::query()
                ->where('company_id', $companyId)
                ->where('code', '2220')
                ->where('is_active', true)
                ->first();

            if ($bank === null || $netPay === null) {
                throw new BusinessRuleException('Bank (1100) or net-pay (2220) account not found.');
            }

            $net = (int) $run->net_total_minor;
            $lines = [
                new PostingLine(
                    accountId: $netPay->id,
                    debitMinor: $net,
                    creditMinor: 0,
                    memo: 'Clear net pay '.$run->period_month,
                ),
                new PostingLine(
                    accountId: $bank->id,
                    debitMinor: 0,
                    creditMinor: $net,
                    memo: 'Payroll payment '.$bankRef,
                ),
            ];

            $journalId = $this->postingService->post(new PostingRequest(
                companyId: $companyId,
                postingDate: $payDate,
                postingKey: PostingKey::make('payroll', $runId, 'pay'),
                sourceType: 'payroll',
                sourceId: $runId,
                eventType: 'pay',
                description: 'Payroll payment '.$run->period_month,
                lines: $lines,
                actorId: $actorId,
                requestId: $this->companyContext->requestId(),
                currency: 'GHS',
            ));

            $run->status = 'paid';
            $run->payment_journal_id = $journalId;
            $run->bank_ref = $bankRef;
            $run->pay_date = $payDate;
            $run->paid_by = $actorId;
            $run->paid_at = now();
            $run->save();

            $this->auditWriter->write(
                action: 'payroll_run.paid',
                entityType: 'payroll_run',
                entityId: $runId,
                detail: ['bank_ref' => $bankRef, 'journal_id' => $journalId],
            );

            return [
                'id' => $run->id,
                'runNo' => $run->run_no,
                'periodMonth' => $run->period_month,
                'status' => $run->status,
                'bankRef' => $bankRef,
                'paymentJournalId' => $journalId,
                'paidAt' => $run->paid_at?->toIso8601String(),
            ];
        });
    }
}
