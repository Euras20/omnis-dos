<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\NotFoundException;
use App\Models\CompanyBankAccount;
use App\Modules\Ledger\Domain\PostingKey;
use App\Modules\Ledger\Domain\PostingLine;
use App\Modules\Ledger\Domain\PostingRequest;
use App\Modules\Ledger\Services\PostingService;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use App\Shared\Support\Uuid;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/bank-accounts/{id}/opening-balance — statement opening balance.
 *
 * Posts to the linked ledger account against 3900. Negative = overdraft.
 */
final class PostBankOpeningBalance
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly PostingService $postingService,
        private readonly AuditWriter $auditWriter,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function execute(string $bankAccountId, array $data): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'bank.view');

        $amount = (int) ($data['amountMinor'] ?? 0);
        if ($amount === 0) {
            throw new BusinessRuleException('amountMinor cannot be 0.');
        }

        $asOf = (string) ($data['asOfDate'] ?? '');
        if ($asOf === '') {
            throw new BusinessRuleException('asOfDate is required.');
        }

        $companyId = $this->companyContext->companyId();

        return DB::transaction(function () use ($bankAccountId, $amount, $asOf, $companyId, $actorId) {
            $bank = CompanyBankAccount::query()
                ->where('company_id', $companyId)
                ->where('id', $bankAccountId)
                ->lockForUpdate()
                ->first();

            if ($bank === null) {
                throw new NotFoundException('Bank account not found.');
            }

            if ($bank->gl_account_id === null) {
                throw new BusinessRuleException('Bank account is not linked to a ledger account.');
            }

            if ($bank->opening_balance_doc_id !== null) {
                throw new BusinessRuleException('Opening balance already recorded. Correct via journal.');
            }

            $clearingId = $this->clearingId($companyId);
            $docId = Uuid::v7();

            $debit = $amount > 0 ? $amount : 0;
            $credit = $amount < 0 ? -$amount : 0;

            $journalId = $this->postingService->post(new PostingRequest(
                companyId: $companyId,
                postingDate: $asOf,
                postingKey: PostingKey::make('opening_balance', $docId, 'bank'),
                sourceType: 'opening_balance',
                sourceId: $docId,
                eventType: 'bank_opening',
                description: "Opening balance {$bank->bank_name}",
                lines: [
                    new PostingLine(accountId: $bank->gl_account_id, debitMinor: $debit, creditMinor: $credit),
                    new PostingLine(accountId: $clearingId, debitMinor: $credit, creditMinor: $debit),
                ],
                actorId: $actorId,
                currency: $bank->currency ?? 'GHS',
            ));

            $bank->opening_balance_doc_id = $docId;
            $bank->opening_balance_date = $asOf;
            $bank->opening_balance_minor = $amount;
            $bank->save();

            $this->auditWriter->write(
                action: 'bank_account.opening_balance',
                entityType: 'company_bank_account',
                entityId: $bank->id,
                detail: ['amountMinor' => $amount, 'asOf' => $asOf, 'journalId' => $journalId],
                actorId: $actorId,
            );

            return [
                'bankAccountId' => $bank->id,
                'docId' => $docId,
                'amountMinor' => $amount,
                'asOfDate' => $asOf,
                'journalId' => $journalId,
            ];
        });
    }

    private function clearingId(string $companyId): string
    {
        $id = \App\Models\Account::query()
            ->where('company_id', $companyId)
            ->where('code', '3900')
            ->value('id');

        if ($id === null) {
            throw new BusinessRuleException('Opening balance clearing account (3900) is missing.');
        }

        return (string) $id;
    }
}
