<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Queries;

use App\Models\CompanyBankAccount;
use App\Shared\Company\CompanyContext;

/**
 * GET /api/v1/bank-accounts — company bank accounts for invoice and quotation templates.
 */
final class ListBankAccountsQuery
{
    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {
    }

    /**
     * @return array{items: list<array<string, mixed>>}
     */
    public function execute(): array
    {
        $companyId = $this->companyContext->companyId();

        $items = CompanyBankAccount::query()
            ->where('company_id', $companyId)
            ->orderBy('bank_name')
            ->get()
            ->map(static fn (CompanyBankAccount $a) => [
                'id' => $a->id,
                'bankName' => $a->bank_name,
                'accountName' => $a->account_name,
                'accountNumber' => $a->account_number,
                'branch' => $a->branch,
                'swift' => $a->swift,
                'currency' => $a->currency,
                'isActive' => (bool) $a->is_active,
            ])
            ->all();

        return ['items' => $items];
    }
}
