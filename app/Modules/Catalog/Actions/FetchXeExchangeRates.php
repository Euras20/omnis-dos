<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Models\ExchangeRate;
use App\Modules\Catalog\Services\XeFxRateService;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use App\Shared\Support\Uuid;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/fx/rates/fetch-xe
 *
 * Fetches mid-market rates (XE.com when credentials are present) and stores
 * them with a 3% selling markup for the requested date.
 */
final class FetchXeExchangeRates
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly AuditWriter $auditWriter,
        private readonly XeFxRateService $xeFxRateService,
    ) {
    }

    /**
     * @param  array{date?: ?string, currencies?: list<string>}  $data
     * @return array{date: string, rates: list<array<string, mixed>>}
     */
    public function execute(array $data = []): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'fx.manage');
        $companyId = $this->companyContext->companyId();

        $date = $data['date'] ?? date('Y-m-d');
        $currencies = $data['currencies'] ?? ['USD', 'EUR', 'CNY'];

        $fetched = $this->xeFxRateService->fetch($currencies);

        return DB::transaction(function () use ($date, $fetched, $companyId, $actorId) {
            $result = [];

            foreach ($fetched as $row) {
                $existing = ExchangeRate::query()
                    ->where('company_id', $companyId)
                    ->where('currency', $row['currency'])
                    ->where('rate_date', $date)
                    ->first();

                if ($existing !== null) {
                    $existing->buy_rate = $row['buyRate'];
                    $existing->sell_rate = $row['sellRate'];
                    $existing->mid_rate = $row['midRate'];
                    $existing->source = $row['source'];
                    $existing->save();
                    $rate = $existing;
                } else {
                    $rate = new ExchangeRate;
                    $rate->id = Uuid::v7();
                    $rate->company_id = $companyId;
                    $rate->currency = $row['currency'];
                    $rate->rate_date = $date;
                    $rate->buy_rate = $row['buyRate'];
                    $rate->sell_rate = $row['sellRate'];
                    $rate->mid_rate = $row['midRate'];
                    $rate->source = $row['source'];
                    $rate->save();
                }

                $result[] = UpsertExchangeRates::toArray($rate);
            }

            $this->auditWriter->write(
                action: 'exchange_rates.xe_fetched',
                entityType: 'exchange_rate',
                entityId: null,
                detail: [
                    'date' => $date,
                    'currencies' => array_column($result, 'currency'),
                    'source' => $result[0]['source'] ?? null,
                    'sellMarkupPercent' => 3,
                ],
                actorId: $actorId,
            );

            return [
                'date' => $date,
                'rates' => $result,
            ];
        });
    }
}
