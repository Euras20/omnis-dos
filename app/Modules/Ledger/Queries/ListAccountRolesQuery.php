<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Queries;

use App\Models\Account;
use App\Models\AccountRoleMapping;
use App\Modules\Ledger\Domain\AccountRoleCatalogue;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;

/**
 * GET /api/v1/accounts/roles — catalogue with the mapped posting account, if any.
 */
final class ListAccountRolesQuery
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
    ) {
    }

    /**
     * @return array{items: list<array<string, mixed>>}
     */
    public function execute(): array
    {
        $this->permissionChecker->assertCan($this->companyContext->userId(), 'ledger.read');

        $companyId = $this->companyContext->companyId();
        $mappings = AccountRoleMapping::query()
            ->where('company_id', $companyId)
            ->get()
            ->keyBy('role_key');

        $accountIds = $mappings->pluck('account_id')->filter()->all();
        $accounts = Account::query()
            ->where('company_id', $companyId)
            ->whereIn('id', $accountIds)
            ->get()
            ->keyBy('id');

        $items = [];
        foreach (AccountRoleCatalogue::all() as $key => $meta) {
            $mapping = $mappings->get($key);
            $account = $mapping !== null ? $accounts->get($mapping->account_id) : null;
            $items[] = [
                'roleKey' => $key,
                'label' => $meta['label'],
                'accountClass' => $meta['accountClass'],
                'accountId' => $account?->id,
                'accountName' => $account?->name,
                'accountCode' => $account?->code,
                'mapped' => $account !== null,
            ];
        }

        return ['items' => $items];
    }
}
