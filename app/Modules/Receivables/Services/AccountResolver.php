<?php

declare(strict_types=1);

namespace App\Modules\Receivables\Services;

use App\Exceptions\BusinessRuleException;
use App\Modules\Ledger\Services\RoleResolver;

/**
 * Resolves AR posting roles to account UUIDs.
 * Codes are not used. Unmapped roles fail with a finance-setup error.
 */
final class AccountResolver
{
    private const ROLES = [
        'ar' => 'CUSTOMER_AR',
        'advances' => 'CUSTOMER_ADVANCES',
        'revenue' => 'SERVICE_REVENUE',
        'vat' => 'VAT_PAYABLE',
        'nhil' => 'NHIL_PAYABLE',
        'getfund' => 'GETFUND_PAYABLE',
        'covid' => 'COVID_PAYABLE',
        'wht_receivable' => 'WHT_RECEIVABLE',
        'cash' => 'BANK_CASH',
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

    public function idByCashCode(string $companyId, string $cashCode): string
    {
        if ($this->isUuid($cashCode)) {
            return $cashCode;
        }

        return $this->roles->id($companyId, 'BANK_CASH');
    }

    private function isUuid(string $value): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $value
        );
    }
}
