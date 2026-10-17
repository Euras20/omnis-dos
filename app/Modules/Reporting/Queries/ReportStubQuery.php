<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Queries;

use App\Shared\Company\CompanyContext;

/**
 * Lightweight stubs for remaining report screens.
 *
 * These return a stable shape (columns + rows) so the frontend can render.
 * They do not invent full financial calculations — trial balance and general
 * ledger are the authoritative computed reports (Step 16.8). Stock and
 * logistics stubs pull simple aggregates where the data already exists;
 * other keys return an empty structured result with a note.
 */
final class ReportStubQuery
{
    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function stock(): array
    {
        $companyId = $this->companyContext->companyId();

        $valuation = \Illuminate\Support\Facades\DB::table('stock_on_hand as s')
            ->join('location as l', 'l.id', '=', 's.location_id')
            ->where('s.company_id', $companyId)
            ->groupBy('l.id', 'l.name')
            ->selectRaw('l.name as location, sum(s.value_minor) as value_minor')
            ->get();

        return [
            'key' => 'stock',
            'title' => 'Stock valuation, ageing & reorder',
            'stub' => true,
            'valuation' => $valuation->map(fn ($r) => [
                'location' => $r->location,
                'valueMinor' => (int) $r->value_minor,
            ])->all(),
            'ageing' => [],
            'reorder' => [],
            'note' => 'Valuation uses current stock_on_hand. Ageing and reorder are stubs pending full lot-cost history.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function logistics(): array
    {
        return [
            'key' => 'logistics',
            'title' => 'Logistics reports',
            'stub' => true,
            'transit' => [],
            'note' => 'Transit performance stub. Live consignment ETA data is available on the logistics endpoints.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function management(): array
    {
        return [
            'key' => 'management',
            'title' => 'Management report',
            'stub' => true,
            'headlines' => [],
            'exceptions' => [],
            'note' => 'Management headlines stub. Derived from posted documents once the reporting pack is completed.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function performance(?string $view = null): array
    {
        $view ??= 'profit_by_product';

        return [
            'key' => 'performance',
            'view' => $view,
            'title' => 'Performance centre',
            'stub' => true,
            'columns' => $view === 'profit_by_customer'
                ? ['customer', 'revenueMinor', 'cogsMinor', 'grossProfitMinor', 'marginPct']
                : ['sku', 'name', 'revenueMinor', 'cogsMinor', 'grossProfitMinor', 'marginPct'],
            'rows' => [],
            'note' => 'Performance views are stubs. Use trial balance / general ledger for posted figures.',
        ];
    }

    /**
     * Generic stub for any other report key the frontend may request.
     *
     * @return array<string, mixed>
     */
    public function generic(string $key): array
    {
        $catalogue = (new ReportCatalogueQuery())->execute();
        $known = collect($catalogue['reports'])->firstWhere('key', $key);

        return [
            'key' => $key,
            'title' => $known['title'] ?? $key,
            'group' => $known['group'] ?? null,
            'stub' => true,
            'columns' => [],
            'rows' => [],
            'note' => 'Lightweight report stub. Authoritative posted figures are on trial-balance and general-ledger.',
        ];
    }
}
