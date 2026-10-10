<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Exceptions\BusinessRuleException;
use App\Models\ExchangeRate;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use App\Shared\Support\Uuid;
use Illuminate\Support\Facades\DB;

/**
 * PUT /api/v1/fx/rates/{date} — enter bank buying/selling rates for a date.
 *
 * Body: { rates: [ { currency: "USD", buyRate: 15.2, sellRate: 15.5, midRate?: 15.35 } ] }
 */
final class UpsertExchangeRates
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly AuditWriter $auditWriter,
    ) {
    }

    /**
     * @param  array{rates: list<array<string, mixed>>, source?: ?string}  $data
     * @return array{date: string, rates: list<array<string, mixed>>}
     */
    public function execute(string $date, array $data): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'fx.manage');
        $companyId = $this->companyContext->companyId();

        $rates = $data['rates'] ?? [];
        if (! is_array($rates) || $rates === []) {
            throw new BusinessRuleException('rates must be a non-empty array.');
        }

        return DB::transaction(function () use ($date, $data, $rates, $companyId, $actorId) {
            $result = [];

            foreach ($rates as $row) {
                $currency = strtoupper(trim((string) ($row['currency'] ?? '')));
                if (strlen($currency) !== 3) {
                    throw new BusinessRuleException('Each rate requires a 3-letter currency code.');
                }

                $buy = $row['buyRate'] ?? $row['buy'] ?? null;
                $sell = $row['sellRate'] ?? $row['sell'] ?? null;
                if ($buy === null || $sell === null) {
                    throw new BusinessRuleException("buyRate and sellRate are required for {$currency}.");
                }

                $mid = $row['midRate'] ?? $row['mid'] ?? null;
                if ($mid === null) {
                    $mid = ((float) $buy + (float) $sell) / 2;
                }

                $existing = ExchangeRate::query()
                    ->where('company_id', $companyId)
                    ->where('currency', $currency)
                    ->where('rate_date', $date)
                    ->first();

                if ($existing !== null) {
                    $existing->buy_rate = $buy;
                    $existing->sell_rate = $sell;
                    $existing->mid_rate = $mid;
                    $existing->source = $data['source'] ?? $existing->source ?? 'manual';
                    $existing->save();
                    $rate = $existing;
                } else {
                    $rate = new ExchangeRate;
                    $rate->id = Uuid::v7();
                    $rate->company_id = $companyId;
                    $rate->currency = $currency;
                    $rate->rate_date = $date;
                    $rate->buy_rate = $buy;
                    $rate->sell_rate = $sell;
                    $rate->mid_rate = $mid;
                    $rate->source = $data['source'] ?? 'manual';
                    $rate->save();
                }

                $result[] = self::toArray($rate);
            }

            $this->auditWriter->write(
                action: 'exchange_rates.upserted',
                entityType: 'exchange_rate',
                entityId: $date,
                detail: [
                    'date' => $date,
                    'currencies' => array_column($result, 'currency'),
                ],
                actorId: $actorId,
            );

            return [
                'date' => $date,
                'rates' => $result,
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public static function toArray(ExchangeRate $rate): array
    {
        return [
            'id' => (string) $rate->id,
            'currency' => $rate->currency,
            'rateDate' => $rate->rate_date?->format('Y-m-d'),
            'buyRate' => (float) $rate->buy_rate,
            'sellRate' => (float) $rate->sell_rate,
            'midRate' => $rate->mid_rate !== null ? (float) $rate->mid_rate : null,
            'source' => $rate->source,
        ];
    }
}
