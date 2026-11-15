<?php

declare(strict_types=1);

namespace App\Modules\Payables\Services;

use App\Exceptions\BusinessRuleException;
use App\Modules\Ledger\Services\RoleResolver;

/**
 * Resolves AP posting roles to account UUIDs.
 * Codes are not used. Unmapped roles fail with a finance-setup error.
 */
final class AccountResolver
{
    private const ROLES = [
        'ap' => 'TRADE_PAYABLE',
        'expense' => 'COGS',
        'claimable_tax' => 'CLAIMABLE_TAX',
        'wht_payable' => 'WHT_PAYABLE',
        'cash' => 'BANK_CASH',
        'grni' => 'GRNI',
    ];

    public function __construct(
        private readonly RoleResolver $roles,
    ) {
    }

    public function idByCode(string $companyId, string $logical): string
    {
        $role = self::ROLES[$logical] ?? null;
        if ($role === null) {
            throw new BusinessRuleException(
                "Finance setup: '{$logical}' is not a posting role. Map a role or pass an account id."
            );
        }

        return $this->roles->id($companyId, $role);
    }
}
