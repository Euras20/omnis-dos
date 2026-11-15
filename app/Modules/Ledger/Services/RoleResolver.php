<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Account;
use App\Models\AccountRoleMapping;
use App\Modules\Ledger\Domain\AccountRoleCatalogue;

/**
 * Resolves a posting role to the client posting account UUID.
 * Never looks up by code. Fails with a setup error when the role is unmapped.
 */
final class RoleResolver
{
    public function id(string $companyId, string $roleKey): string
    {
        if (! AccountRoleCatalogue::has($roleKey)) {
            throw new BusinessRuleException("Unknown account role '{$roleKey}'.");
        }

        $mapping = AccountRoleMapping::query()
            ->where('company_id', $companyId)
            ->where('role_key', $roleKey)
            ->first();

        if ($mapping === null) {
            $label = AccountRoleCatalogue::all()[$roleKey]['label'];
            throw new BusinessRuleException(
                "Finance setup: map the '{$roleKey}' role ({$label}) before posting."
            );
        }

        $account = Account::query()
            ->where('company_id', $companyId)
            ->where('id', $mapping->account_id)
            ->first();

        if ($account === null || ! $account->is_active || ($account->node_type ?? 'POSTING') !== 'POSTING') {
            throw new BusinessRuleException(
                "Finance setup: role '{$roleKey}' must point to an active posting account."
            );
        }

        return $account->id;
    }

    public function idOrNull(string $companyId, string $roleKey): ?string
    {
        try {
            return $this->id($companyId, $roleKey);
        } catch (BusinessRuleException) {
            return null;
        }
    }
}
