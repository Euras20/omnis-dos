<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Actions;

use App\Exceptions\BusinessRuleException;
use App\Models\Account;
use App\Models\CompanyBankAccount;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use App\Shared\Support\Uuid;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/bank-accounts — add a company bank account linked to a ledger account.
 */
final class CreateBankAccount
{
    private const TYPES = ['Bank', 'Mobile money', 'Petty cash'];

    private const DETAIL = [
        'Bank' => 'Bank',
        'Mobile money' => 'Mobile money',
        'Petty cash' => 'Petty cash / cash on hand',
    ];

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

        $type = $data['type'] ?? 'Bank';
        if (! in_array($type, self::TYPES, true)) {
            throw new BusinessRuleException('type must be Bank, Mobile money or Petty cash.');
        }

        return DB::transaction(function () use ($data, $type, $companyId, $actorId) {
            $glAccountId = $data['glAccountId'] ?? null;

            if ($glAccountId === null || $glAccountId === '@new') {
                $glAccountId = $this->createLedgerAccount($data, $type, $companyId, $actorId);
            } else {
                $gl = Account::query()
                    ->where('company_id', $companyId)
                    ->where('id', $glAccountId)
                    ->where('is_active', true)
                    ->first();

                if ($gl === null) {
                    throw new BusinessRuleException('Ledger account not found or inactive.');
                }

                if ($gl->kind !== 'bank') {
                    $gl->kind = 'bank';
                    $gl->save();
                }
            }

            $account = CompanyBankAccount::query()->create([
                'id' => Uuid::v7(),
                'company_id' => $companyId,
                'type' => $type,
                'gl_account_id' => $glAccountId,
                'bank_name' => trim((string) $data['bankName']),
                'account_name' => trim((string) $data['accountName']),
                'account_number' => trim((string) ($data['accountNumber'] ?? '')),
                'branch' => isset($data['branch']) ? trim((string) $data['branch']) : null,
                'swift' => isset($data['swift']) ? strtoupper(trim((string) $data['swift'])) : null,
                'currency' => strtoupper((string) ($data['currency'] ?? 'GHS')),
                'is_active' => (bool) ($data['isActive'] ?? true),
            ]);

            $this->auditWriter->write(
                action: 'bank_account.created',
                entityType: 'company_bank_account',
                entityId: $account->id,
                detail: [
                    'bankName' => $account->bank_name,
                    'accountNumber' => $account->account_number,
                    'glAccountId' => $glAccountId,
                    'type' => $type,
                ],
                actorId: $actorId,
            );

            return $this->toArray($account);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createLedgerAccount(array $data, string $type, string $companyId, string $actorId): string
    {
        $name = trim((string) ($data['glAccountName'] ?? ''));
        if ($name === '') {
            $name = 'Bank – '.trim((string) $data['bankName']).' '.strtoupper((string) ($data['currency'] ?? 'GHS'));
        }

        $exists = Account::query()
            ->where('company_id', $companyId)
            ->whereRaw('lower(name) = ?', [strtolower($name)])
            ->exists();

        if ($exists) {
            throw new BusinessRuleException("A ledger account named '{$name}' already exists. Pick it instead.");
        }

        $code = $this->nextAssetCode($companyId);

        $account = new Account;
        $account->id = Uuid::v7();
        $account->company_id = $companyId;
        $account->code = $code;
        $account->name = $name;
        $account->category = 'asset';
        $account->detail_type = self::DETAIL[$type];
        $account->kind = 'bank';
        $account->node_type = 'POSTING';
        $account->source = 'CLIENT';
        $account->currency = strtoupper((string) ($data['currency'] ?? 'GHS'));
        $account->is_active = true;
        $account->normal_balance = 'debit';
        $account->description = 'Ledger account for '.trim((string) $data['bankName']);
        $account->save();

        $this->auditWriter->write(
            action: 'account.created',
            entityType: 'account',
            entityId: $account->id,
            detail: ['code' => $code, 'name' => $name, 'forBankAccount' => true],
            actorId: $actorId,
        );

        return $account->id;
    }

    private function nextAssetCode(string $companyId): string
    {
        $used = Account::query()
            ->where('company_id', $companyId)
            ->whereNotNull('code')
            ->whereRaw('code ~ ?', ['^[0-9]+$'])
            ->pluck('code')
            ->map(fn ($c) => (int) $c)
            ->filter(fn ($n) => $n >= 1000 && $n <= 1999)
            ->all();

        $usedSet = array_flip($used);
        $candidate = empty($used) ? 1000 : (int) (floor(max($used) / 10) * 10 + 10);

        while ($candidate <= 1999 && isset($usedSet[$candidate])) {
            $candidate += 10;
        }

        if ($candidate <= 1999) {
            return (string) $candidate;
        }

        for ($n = 1000; $n <= 1999; $n++) {
            if (! isset($usedSet[$n])) {
                return (string) $n;
            }
        }

        throw new BusinessRuleException('No free account number left in the asset range.');
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(CompanyBankAccount $account): array
    {
        $gl = $account->glAccount;

        return [
            'id' => $account->id,
            'type' => $account->type,
            'glAccountId' => $account->gl_account_id,
            'glAccountCode' => $gl?->code,
            'glAccountName' => $gl?->name,
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
