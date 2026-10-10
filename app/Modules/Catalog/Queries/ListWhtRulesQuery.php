<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Queries;

use App\Models\WhtRuleVersion;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;

/**
 * GET /api/v1/tax/wht — withholding rates in force.
 */
final class ListWhtRulesQuery
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

        $query = WhtRuleVersion::query()->orderBy('code')->orderByDesc('effective_from');

        $date = $filters['date'] ?? date('Y-m-d');
        $query->where('effective_from', '<=', $date)
            ->where(function ($q) use ($date) {
                $q->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', $date);
            })
            ->where('is_active', true);

        $items = $query->get()->map(fn (WhtRuleVersion $r) => [
            'id' => (string) $r->id,
            'code' => $r->code,
            'name' => $r->name,
            'effectiveFrom' => $r->effective_from?->format('Y-m-d'),
            'effectiveTo' => $r->effective_to?->format('Y-m-d'),
            'ratePercent' => (float) $r->rate_percent,
            'appliesTo' => $r->applies_to,
            'notes' => $r->notes,
            'isActive' => (bool) $r->is_active,
        ])->all();

        return ['items' => $items];
    }
}
