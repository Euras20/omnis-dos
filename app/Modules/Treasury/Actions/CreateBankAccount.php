<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Actions;

use App\Models\CompanyBankAccount;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use App\Shared\Support\Uuid;

/**
 * POST /api/v1/bank-accounts — add a company bank account printed on invoices and quotations.
 */
final class CreateBankAccount
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
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
        $this->permissionChecker->assertCan($actorId, 'bank.view');
        $companyId = $this->companyContext->companyId();

        $account = CompanyBankAccount::query()->create([
            'id' => Uuid::v7(),
            'company_id' => $companyId,
            'bank_name' => trim((string) $data['bankName']),
            'account_name' => trim((string) $data['accountName']),
            'account_number' => trim((string) $data['accountNumber']),
            'branch' => isset($data['branch']) ? trim((string) $data['branch']) : null,
            'swift' => isset($data['swift']) ? strtoupper(trim((string) $data['swift'])) : null,
            'currency' => strtoupper((string) ($data['currency'] ?? 'GHS')),
            'is_active' => (bool) ($data['isActive'] ?? true),
        ]);

        $this->auditWriter->write(
            action: 'bank_account.created',
            entityType: 'company_bank_account',
            entityId: $account->id,
            detail: ['bankName' => $account->bank_name, 'accountNumber' => $account->account_number],
            actorId: $actorId,
        );

        return $this->toArray($account);
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(CompanyBankAccount $account): array
    {
        return [
            'id' => $account->id,
            'bankName' => $account->bank_name,
            'accountName' => $account->account_name,
            'accountNumber' => $account->account_number,
            'branch' => $account->branch,
            'swift' => $account->swift,
            'currency' => $account->currency,
            'isActive' => (bool) $account->is_active,
        ];
    }
}
