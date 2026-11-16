<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Queries;

use App\Models\Account;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;

/**
 * GET /api/v1/accounts/tree — system groups plus client accounts, nested.
 */
final class AccountTreeQuery
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

        $accounts = Account::query()
            ->where('company_id', $this->companyContext->companyId())
            ->orderBy('level')
            ->orderBy('name')
            ->get();

        $nodes = [];
        foreach ($accounts as $account) {
            $nodes[$account->id] = $this->node($account);
        }

        $roots = [];
        foreach ($accounts as $account) {
            if ($account->parent_id !== null && isset($nodes[$account->parent_id])) {
                $nodes[$account->parent_id]['children'][] = &$nodes[$account->id];
            } else {
                $roots[] = &$nodes[$account->id];
            }
        }

        return ['items' => $roots];
    }

    /**
     * @return array<string, mixed>
     */
    private function node(Account $account): array
    {
        return [
            'id' => $account->id,
            'parentId' => $account->parent_id,
            'systemKey' => $account->system_key,
            'code' => $account->code,
            'name' => $account->name,
            'category' => $account->category,
            'detailType' => $account->detail_type,
            'description' => $account->description,
            'nodeType' => $account->node_type ?? 'POSTING',
            'source' => $account->source ?? 'CLIENT',
            'isPostable' => ($account->node_type ?? 'POSTING') === 'POSTING',
            'isContra' => (bool) $account->is_contra,
            'normalBalance' => $account->normal_balance,
            'statement' => $account->statement,
            'reportSection' => $account->report_section,
            'level' => $account->level,
            'isActive' => $account->is_active,
            'isLocked' => (bool) $account->is_locked,
            'lockedBy' => $account->locked_by,
            'lockedAt' => $account->locked_at?->toIso8601String(),
            'lockReason' => $account->lock_reason,
            'rowVersion' => $account->row_version,
            'children' => [],
        ];
    }
}
