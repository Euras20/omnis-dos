<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\NotFoundException;
use App\Models\Account;
use App\Models\CashTransfer;
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
 * POST /api/v1/transfers — move money between bank / MoMo / petty cash.
 */
final class PostCashTransfer
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
        $transferDate = (string) ($data['transferDate'] ?? $data['date'] ?? '');
        $fromId = (string) ($data['fromAccountId'] ?? $data['from_account_id'] ?? '');
        $toId = (string) ($data['toAccountId'] ?? $data['to_account_id'] ?? '');
        $amount = (int) ($data['amountMinor'] ?? $data['amount_minor'] ?? 0);

        if ($transferDate === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $transferDate)) {
            throw new BusinessRuleException('transferDate must be YYYY-MM-DD.');
        }
        if ($fromId === '' || $toId === '' || $fromId === $toId) {
            throw new BusinessRuleException('fromAccountId and toAccountId are required and must differ.');
        }
        if ($amount <= 0) {
            throw new BusinessRuleException('amountMinor must be > 0.');
        }

        $this->periodLockChecker->assertOpen($transferDate);

        return DB::transaction(function () use (
            $companyId,
            $actorId,
            $transferDate,
            $fromId,
            $toId,
            $amount,
            $data
        ) {
            $from = Account::query()->where('company_id', $companyId)->where('id', $fromId)->where('is_active', true)->first();
            $to = Account::query()->where('company_id', $companyId)->where('id', $toId)->where('is_active', true)->first();
            if ($from === null || $to === null) {
                throw new NotFoundException('From or to account not found.');
            }

            $transferNo = $this->allocateNo($companyId);
            $id = Uuid::v7();
            $transfer = new CashTransfer([
                'id' => $id,
                'company_id' => $companyId,
                'transfer_no' => $transferNo,
                'transfer_date' => $transferDate,
                'from_account_id' => $fromId,
                'to_account_id' => $toId,
                'amount_minor' => $amount,
                'currency' => strtoupper((string) ($data['currency'] ?? 'GHS')),
                'memo' => $data['memo'] ?? null,
                'created_by' => $actorId,
                'request_id' => $this->companyContext->requestId(),
            ]);
            $transfer->save();

            $lines = [
                new PostingLine(
                    accountId: $toId,
                    debitMinor: $amount,
                    creditMinor: 0,
                    memo: 'Transfer in '.$transferNo,
                ),
                new PostingLine(
                    accountId: $fromId,
                    debitMinor: 0,
                    creditMinor: $amount,
                    memo: 'Transfer out '.$transferNo,
                ),
            ];

            $journalId = $this->postingService->post(new PostingRequest(
                companyId: $companyId,
                postingDate: $transferDate,
                postingKey: PostingKey::make('transfer', $id, 'post'),
                sourceType: 'transfer',
                sourceId: $id,
                eventType: 'post',
                description: 'Cash transfer '.$transferNo,
                lines: $lines,
                actorId: $actorId,
                requestId: $this->companyContext->requestId(),
                currency: $transfer->currency,
            ));
            $transfer->journal_id = $journalId;
            $transfer->save();

            $this->auditWriter->write(
                action: 'cash_transfer.posted',
                entityType: 'cash_transfer',
                entityId: $id,
                detail: ['transfer_no' => $transferNo, 'amount_minor' => $amount],
            );

            return [
                'id' => $id,
                'transferNo' => $transferNo,
                'transferDate' => $transferDate,
                'fromAccountId' => $fromId,
                'toAccountId' => $toId,
                'amountMinor' => $amount,
                'journalId' => $journalId,
            ];
        });
    }

    private function allocateNo(string $companyId): string
    {
        $seq = NumberSequence::query()
            ->where('company_id', $companyId)
            ->where('doc_type', 'cash_transfer')
            ->lockForUpdate()
            ->first();

        if ($seq === null) {
            $seq = new NumberSequence([
                'id' => Uuid::v7(),
                'company_id' => $companyId,
                'doc_type' => 'cash_transfer',
                'fiscal_year' => (int) date('Y'),
                'next_value' => 1,
            ]);
            $seq->save();
        }

        $n = (int) $seq->next_value;
        $seq->next_value = $n + 1;
        $seq->save();

        return sprintf('TRF-%06d', $n);
    }
}
