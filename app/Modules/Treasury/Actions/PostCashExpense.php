<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\NotFoundException;
use App\Models\Account;
use App\Models\CashExpense;
use App\Models\CashExpenseLine;
use App\Models\NumberSequence;
use App\Modules\Ledger\Domain\PostingKey;
use App\Modules\Ledger\Domain\PostingLine;
use App\Modules\Ledger\Domain\PostingRequest;
use App\Modules\Ledger\Services\PostingService;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use App\Shared\PeriodLock\PeriodLockChecker;
use App\Shared\Support\Uuid;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/expenses — record expense/cheque from bank, MoMo or petty cash.
 */
final class PostCashExpense
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
    public function execute(array $data): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'cash.spend');

        $companyId = $this->companyContext->companyId();
        $expenseDate = (string) ($data['expenseDate'] ?? $data['date'] ?? '');
        $paidFromAccountId = (string) ($data['paidFromAccountId'] ?? $data['paid_from_account_id'] ?? '');
        $rawLines = $data['lines'] ?? [];

        if ($expenseDate === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $expenseDate)) {
            throw new BusinessRuleException('expenseDate must be YYYY-MM-DD.');
        }
        if ($paidFromAccountId === '') {
            throw new BusinessRuleException('paidFromAccountId is required.');
        }
        if (! is_array($rawLines) || $rawLines === []) {
            throw new BusinessRuleException('At least one expense line is required.');
        }

        $this->periodLockChecker->assertOpen($expenseDate);

        return DB::transaction(function () use (
            $companyId,
            $actorId,
            $expenseDate,
            $paidFromAccountId,
            $rawLines,
            $data
        ) {
            $paidFrom = Account::query()
                ->where('company_id', $companyId)
                ->where('id', $paidFromAccountId)
                ->where('is_active', true)
                ->first();
            if ($paidFrom === null) {
                throw new NotFoundException('Paid-from account not found.');
            }

            $gross = 0;
            $tax = 0;
            $parsed = [];
            $lineNo = 0;
            foreach ($rawLines as $row) {
                $lineNo++;
                $accId = (string) ($row['expenseAccountId'] ?? $row['expense_account_id'] ?? '');
                $amount = (int) ($row['amountMinor'] ?? $row['amount_minor'] ?? 0);
                $taxMinor = (int) ($row['taxMinor'] ?? $row['tax_minor'] ?? 0);
                $desc = trim((string) ($row['description'] ?? ''));
                if ($accId === '' || $amount <= 0) {
                    throw new BusinessRuleException("Line {$lineNo}: expenseAccountId and positive amountMinor required.");
                }
                $acc = Account::query()
                    ->where('company_id', $companyId)
                    ->where('id', $accId)
                    ->first();
                if ($acc === null) {
                    throw new NotFoundException("Expense account not found on line {$lineNo}.");
                }
                $parsed[] = [
                    'line_no' => $lineNo,
                    'expense_account_id' => $accId,
                    'description' => $desc !== '' ? $desc : $acc->name,
                    'amount_minor' => $amount,
                    'tax_minor' => $taxMinor,
                ];
                $gross += $amount + $taxMinor;
                $tax += $taxMinor;
            }

            $expenseNo = $this->allocateNo($companyId);
            $id = Uuid::v7();
            $expense = new CashExpense([
                'id' => $id,
                'company_id' => $companyId,
                'expense_no' => $expenseNo,
                'expense_date' => $expenseDate,
                'paid_from' => (string) ($data['paidFrom'] ?? 'bank'),
                'paid_from_account_id' => $paidFromAccountId,
                'payee' => $data['payee'] ?? null,
                'method' => $data['method'] ?? null,
                'cheque_no' => $data['chequeNo'] ?? $data['cheque_no'] ?? null,
                'currency' => strtoupper((string) ($data['currency'] ?? 'GHS')),
                'gross_minor' => $gross,
                'tax_minor' => $tax,
                'net_minor' => $gross - $tax,
                'memo' => $data['memo'] ?? null,
                'created_by' => $actorId,
                'request_id' => $this->companyContext->requestId(),
            ]);
            $expense->save();

            $postingLines = [];
            foreach ($parsed as $p) {
                $line = new CashExpenseLine([
                    'id' => Uuid::v7(),
                    'company_id' => $companyId,
                    'cash_expense_id' => $id,
                    'line_no' => $p['line_no'],
                    'expense_account_id' => $p['expense_account_id'],
                    'description' => $p['description'],
                    'amount_minor' => $p['amount_minor'],
                    'tax_minor' => $p['tax_minor'],
                ]);
                $line->save();

                $postingLines[] = new PostingLine(
                    accountId: $p['expense_account_id'],
                    debitMinor: $p['amount_minor'] + $p['tax_minor'],
                    creditMinor: 0,
                    memo: $p['description'],
                );
            }

            $postingLines[] = new PostingLine(
                accountId: $paidFromAccountId,
                debitMinor: 0,
                creditMinor: $gross,
                memo: 'Cash expense '.$expenseNo,
            );

            $journalId = $this->postingService->post(new PostingRequest(
                companyId: $companyId,
                postingDate: $expenseDate,
                postingKey: PostingKey::make('expense', $id, 'post'),
                sourceType: 'expense',
                sourceId: $id,
                eventType: 'post',
                description: 'Expense '.$expenseNo,
                lines: $postingLines,
                actorId: $actorId,
                requestId: $this->companyContext->requestId(),
                currency: $expense->currency,
            ));
            $expense->journal_id = $journalId;
            $expense->save();

            $this->auditWriter->write(
                action: 'cash_expense.posted',
                entityType: 'cash_expense',
                entityId: $id,
                detail: ['expense_no' => $expenseNo, 'gross_minor' => $gross],
            );

            return [
                'id' => $id,
                'expenseNo' => $expenseNo,
                'expenseDate' => $expenseDate,
                'grossMinor' => $gross,
                'taxMinor' => $tax,
                'journalId' => $journalId,
            ];
        });
    }

    private function allocateNo(string $companyId): string
    {
        $seq = NumberSequence::query()
            ->where('company_id', $companyId)
            ->where('doc_type', 'cash_expense')
            ->lockForUpdate()
            ->first();

        if ($seq === null) {
            $seq = new NumberSequence([
                'id' => Uuid::v7(),
                'company_id' => $companyId,
                'doc_type' => 'cash_expense',
                'fiscal_year' => (int) date('Y'),
                'next_value' => 1,
            ]);
            $seq->save();
        }

        $n = (int) $seq->next_value;
        $seq->next_value = $n + 1;
        $seq->save();

        return sprintf('EXP-%06d', $n);
    }
}
