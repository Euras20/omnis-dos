<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Queries;

use App\Models\TaxRuleVersion;
use App\Modules\Catalog\Actions\CreateTaxRuleVersion;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;

/**
 * GET /api/v1/tax/rules — tax rule versions; ?date returns those in force on that date.
 */
final class ListTaxRulesQuery
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
    ) {
    }

    /**
     * @param  array{date?: ?string}  $filters
     * @return array{items: list<array<string, mixed>>}
     */
    public function execute(array $filters = []): array
    {
        $this->permissionChecker->assertCan($this->companyContext->userId(), 'ledger.read');

        $query = TaxRuleVersion::query()->orderBy('code')->orderByDesc('effective_from');

        if (! empty($filters['date'])) {
            $date = $filters['date'];
            $query->where('effective_from', '<=', $date)
                ->where(function ($q) use ($date) {
                    $q->whereNull('effective_to')
                        ->orWhere('effective_to', '>=', $date);
                });
        }

        $items = $query->get()->map(fn (TaxRuleVersion $r) => CreateTaxRuleVersion::toArray($r))->all();

        return ['items' => $items];
    }
}
