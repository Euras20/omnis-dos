<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\NotFoundException;
use App\Models\Account;
use App\Models\AccountRoleMapping;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use Illuminate\Support\Facades\DB;

/**
 * DELETE /api/v1/accounts/{id}
 *
 * Removes a folder or a posting account that has never been used.
 * Blocked when it has children, journal lines, or a role mapping.
 */
final class DeleteAccount
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly AuditWriter $auditWriter,
    ) {
    }

    /**
     * @return array{deleted: bool, id: string}
     */
    public function execute(string $id): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'ledger.accounts.manage');

        $companyId = $this->companyContext->companyId();

        return DB::transaction(function () use ($id, $companyId, $actorId) {
            $account = Account::query()
                ->where('company_id', $companyId)
                ->where('id', $id)
                ->lockForUpdate()
                ->first();

            if ($account === null) {
                throw new NotFoundException('Account not found.');
            }

            $hasChildren = Account::query()
                ->where('company_id', $companyId)
                ->where('parent_id', $account->id)
                ->exists();
            if ($hasChildren) {
                throw new BusinessRuleException('Delete the accounts under this folder first.');
            }

            $hasLines = DB::table('journal_line')
                ->where('company_id', $companyId)
                ->where('account_id', $account->id)
                ->exists();
            if ($hasLines) {
                throw new BusinessRuleException('This account has posted history and cannot be deleted. Deactivate it instead.');
            }

            $mapped = AccountRoleMapping::query()
                ->where('company_id', $companyId)
                ->where('account_id', $account->id)
                ->exists();
            if ($mapped) {
                throw new BusinessRuleException('Unmap the role that uses this account before deleting it.');
            }

            $this->auditWriter->write(
                action: 'account.deleted',
                entityType: 'account',
                entityId: $account->id,
                detail: [
                    'name' => $account->name,
                    'code' => $account->code,
                    'nodeType' => $account->node_type,
                    'source' => $account->source,
                ],
                actorId: $actorId,
            );

            $account->delete();

            return ['deleted' => true, 'id' => $id];
        });
    }
}
