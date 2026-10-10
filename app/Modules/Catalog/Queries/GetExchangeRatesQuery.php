<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Queries;

use App\Models\ExchangeRate;
use App\Modules\Catalog\Actions\UpsertExchangeRates;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/v1/fx/rates — rates for a date (latest on or before it per currency).
 */
final class GetExchangeRatesQuery
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
    ) {
    }

    /**
     * @param  array{date?: ?string, currency?: ?string}  $filters
     * @return array{date: string, rates: list<array<string, mixed>>}
     */
    public function execute(array $filters = []): array
    {
        $actorId = $this->companyContext->userId();
        // Any authenticated finance/ops user may read rates; no dedicated view permission.
        if (! $this->permissionChecker->can($actorId, 'fx.manage')
            && ! $this->permissionChecker->can($actorId, 'ledger.read')
            && ! $this->permissionChecker->can($actorId, 'finance.view')
            && ! $this->permissionChecker->can($actorId, 'sales.orders.view')) {
            $this->permissionChecker->assertCan($actorId, 'ledger.read');
        }

        $date = $filters['date'] ?? date('Y-m-d');
        $companyId = $this->companyContext->companyId();

        // Latest rate on or before the requested date, per currency.
        $sub = ExchangeRate::query()
            ->select('currency', DB::raw('MAX(rate_date) as max_date'))
            ->where('company_id', $companyId)
            ->where('rate_date', '<=', $date)
            ->groupBy('currency');

        if (! empty($filters['currency'])) {
            $sub->where('currency', strtoupper((string) $filters['currency']));
        }

        $rows = ExchangeRate::query()
            ->joinSub($sub, 'latest', function ($join) {
                $join->on('exchange_rate.currency', '=', 'latest.currency')
                    ->on('exchange_rate.rate_date', '=', 'latest.max_date');
            })
            ->where('exchange_rate.company_id', $companyId)
            ->orderBy('exchange_rate.currency')
            ->get(['exchange_rate.*']);

        return [
            'date' => $date,
            'rates' => $rows->map(fn (ExchangeRate $r) => UpsertExchangeRates::toArray($r))->all(),
        ];
    }
}
