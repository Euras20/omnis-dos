<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\NotFoundException;
use App\Models\Account;
use App\Models\AccountRoleMapping;
use App\Modules\Ledger\Domain\AccountRoleCatalogue;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use Illuminate\Support\Facades\DB;

/**
 * PUT /api/v1/accounts/roles/{roleKey} — bind a role to a client posting account.
 */
final class MapAccountRole
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
    public function execute(string $roleKey, array $data): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'ledger.accounts.manage');

        if (! AccountRoleCatalogue::has($roleKey)) {
            throw new BusinessRuleException("Unknown account role '{$roleKey}'.");
        }

        $companyId = $this->companyContext->companyId();
        $accountId = (string) $data['accountId'];

        return DB::transaction(function () use ($roleKey, $accountId, $companyId, $actorId) {
            $account = Account::query()
                ->where('company_id', $companyId)
                ->where('id', $accountId)
                ->first();

            if ($account === null) {
                throw new NotFoundException('Account not found.');
            }
            if (($account->node_type ?? 'POSTING') !== 'POSTING') {
                throw new BusinessRuleException('A role must map to a posting account, not a group.');
            }
            if (! $account->is_active) {
                throw new BusinessRuleException('The account is inactive.');
            }

            $expected = AccountRoleCatalogue::all()[$roleKey]['accountClass'];
            if ($account->category !== $expected) {
                throw new BusinessRuleException(
                    "Role {$roleKey} expects an {$expected} account."
                );
            }

            AccountRoleMapping::query()->updateOrInsert(
                ['company_id' => $companyId, 'role_key' => $roleKey],
                [
                    'account_id' => $account->id,
                    'updated_by' => $actorId,
                    'updated_at' => now(),
                ]
            );

            $this->auditWriter->write(
                action: 'account_role.mapped',
                entityType: 'account_role_mapping',
                entityId: $roleKey,
                detail: ['accountId' => $account->id, 'accountName' => $account->name],
                actorId: $actorId,
            );

            return [
                'roleKey' => $roleKey,
                'accountId' => $account->id,
                'accountName' => $account->name,
                'accountCode' => $account->code,
            ];
        });
    }
}
