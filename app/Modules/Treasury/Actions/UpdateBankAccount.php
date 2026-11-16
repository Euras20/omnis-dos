<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\NotFoundException;
use App\Models\Account;
use App\Models\CompanyBankAccount;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use Illuminate\Support\Facades\DB;

/**
 * PATCH /api/v1/bank-accounts/{id} — edit a company bank account.
 */
final class UpdateBankAccount
{
    private const TYPES = ['Bank', 'Mobile money', 'Petty cash'];

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
    public function execute(string $id, array $data): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'bank.view');
        $companyId = $this->companyContext->companyId();

        return DB::transaction(function () use ($id, $data, $companyId, $actorId) {
            $account = CompanyBankAccount::query()
                ->where('company_id', $companyId)
                ->where('id', $id)
                ->lockForUpdate()
                ->first();

            if ($account === null) {
                throw new NotFoundException('Bank account not found.');
            }

            if (isset($data['type'])) {
                if (! in_array($data['type'], self::TYPES, true)) {
                    throw new BusinessRuleException('type must be Bank, Mobile money or Petty cash.');
                }
                $account->type = $data['type'];
            }

            if (isset($data['glAccountId']) && $data['glAccountId'] !== $account->gl_account_id) {
                $gl = Account::query()
                    ->where('company_id', $companyId)
                    ->where('id', $data['glAccountId'])
                    ->where('is_active', true)
                    ->first();

                if ($gl === null) {
                    throw new BusinessRuleException('Ledger account not found or inactive.');
                }

                if ($gl->kind !== 'bank') {
                    $gl->kind = 'bank';
                    $gl->save();
                }

                $account->gl_account_id = $gl->id;
            }

            if (isset($data['bankName'])) {
                $account->bank_name = trim((string) $data['bankName']);
            }
            if (isset($data['accountName'])) {
                $account->account_name = trim((string) $data['accountName']);
            }
            if (array_key_exists('accountNumber', $data)) {
                $account->account_number = trim((string) ($data['accountNumber'] ?? ''));
            }
            if (array_key_exists('branch', $data)) {
                $account->branch = $data['branch'] !== null ? trim((string) $data['branch']) : null;
            }
            if (array_key_exists('swift', $data)) {
                $account->swift = $data['swift'] !== null ? strtoupper(trim((string) $data['swift'])) : null;
            }
            if (isset($data['currency'])) {
                $account->currency = strtoupper((string) $data['currency']);
            }
            if (isset($data['isActive'])) {
                $account->is_active = (bool) $data['isActive'];
            }

            $account->save();

            $this->auditWriter->write(
                action: 'bank_account.updated',
                entityType: 'company_bank_account',
                entityId: $account->id,
                detail: ['glAccountId' => $account->gl_account_id],
                actorId: $actorId,
            );

            $account->load('glAccount');

            return [
                'id' => $account->id,
                'type' => $account->type,
                'glAccountId' => $account->gl_account_id,
                'glAccountCode' => $account->glAccount?->code,
                'glAccountName' => $account->glAccount?->name,
                'bankName' => $account->bank_name,
                'accountName' => $account->account_name,
                'accountNumber' => $account->account_number,
                'branch' => $account->branch,
                'swift' => $account->swift,
                'currency' => $account->currency,
                'isActive' => (bool) $account->is_active,
            ];
        });
    }
}
