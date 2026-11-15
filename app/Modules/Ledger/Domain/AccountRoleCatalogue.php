<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Domain;

/**
 * Roles that automatic posting will resolve to a client posting account.
 * Codes are not used. A role is unbound until a finance admin maps it.
 */
final class AccountRoleCatalogue
{
    /**
     * @return array<string, array{label: string, accountClass: string}>
     */
    public static function all(): array
    {
        return [
            'CUSTOMER_AR' => ['label' => 'Trade receivables', 'accountClass' => 'asset'],
            'CUSTOMER_ADVANCES' => ['label' => 'Customer advances', 'accountClass' => 'liability'],
            'SERVICE_REVENUE' => ['label' => 'Service revenue', 'accountClass' => 'income'],
            'VAT_PAYABLE' => ['label' => 'VAT payable', 'accountClass' => 'liability'],
            'NHIL_PAYABLE' => ['label' => 'NHIL payable', 'accountClass' => 'liability'],
            'GETFUND_PAYABLE' => ['label' => 'GETFund payable', 'accountClass' => 'liability'],
            'COVID_PAYABLE' => ['label' => 'COVID levy payable (historical)', 'accountClass' => 'liability'],
            'WHT_RECEIVABLE' => ['label' => 'Withholding tax receivable', 'accountClass' => 'asset'],
            'BANK_CASH' => ['label' => 'Bank and cash', 'accountClass' => 'asset'],
            'INVENTORY' => ['label' => 'Inventory', 'accountClass' => 'asset'],
            'COGS' => ['label' => 'Cost of goods sold', 'accountClass' => 'expense'],
            'GRNI' => ['label' => 'Goods received not invoiced', 'accountClass' => 'liability'],
            'STOCK_ADJUSTMENT' => ['label' => 'Stock adjustment', 'accountClass' => 'expense'],
            'TRADE_PAYABLE' => ['label' => 'Trade payables', 'accountClass' => 'liability'],
            'CLAIMABLE_TAX' => ['label' => 'Claimable input tax', 'accountClass' => 'asset'],
            'WHT_PAYABLE' => ['label' => 'Withholding tax payable', 'accountClass' => 'liability'],
            'WAGES' => ['label' => 'Wages expense', 'accountClass' => 'expense'],
            'EMPLOYER_SSF' => ['label' => 'Employer SSF expense', 'accountClass' => 'expense'],
            'PAYE_PAYABLE' => ['label' => 'PAYE payable', 'accountClass' => 'liability'],
            'SSF_PAYABLE' => ['label' => 'SSF payable', 'accountClass' => 'liability'],
            'NET_PAY' => ['label' => 'Net pay payable', 'accountClass' => 'liability'],
            'DEPRECIATION_EXPENSE' => ['label' => 'Depreciation expense', 'accountClass' => 'expense'],
            'ACCUMULATED_DEPRECIATION' => ['label' => 'Accumulated depreciation', 'accountClass' => 'asset'],
        ];
    }

    public static function has(string $roleKey): bool
    {
        return isset(self::all()[$roleKey]);
    }
}
