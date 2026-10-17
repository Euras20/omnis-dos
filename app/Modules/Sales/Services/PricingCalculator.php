<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Models\ExchangeRate;
use App\Models\Item;
use App\Models\PricingParameter;

/**
 * Pure pricing calculator matching the frontend priceBuild / dhlCost / chargeableKg formulas.
 *
 * All money figures returned are GHS (major units, 2 d.p. where the frontend rounds).
 * Does not write to the database.
 */
final class PricingCalculator
{
    /**
     * @param  array<string, mixed>  $parameters  pricing_parameter.parameters (or defaults)
     * @param  array{weightKg?: float|null, dimensions?: array<int, float>|null, buyPriceMinor?: int|null}  $itemData
     * @return array<string, mixed>
     */
    public function calculate(
        array $parameters,
        array $itemData,
        int $qty,
        bool $estimate = false,
        ?string $asOfDate = null,
        string $companyId = '',
    ): array {
        $qty = max(1, $qty);
        $asOfDate = $asOfDate ?? date('Y-m-d');

        $supplierCur = strtoupper((string) ($parameters['supplierCur'] ?? 'USD'));
        $dhlCur = strtoupper((string) ($parameters['dhlCur'] ?? 'USD'));
        $volDivisor = (float) ($parameters['volDivisor'] ?? 5000);
        $dhlMin = (float) ($parameters['dhlMin'] ?? 45);

        $supplierRate = $this->fxRate($companyId, $supplierCur, $asOfDate);
        $dhlRate = $this->fxRate($companyId, $dhlCur, $asOfDate) ?? $supplierRate;

        $buyPriceMinor = $itemData['buyPriceMinor'] ?? null;
        $buyPriceMajor = $buyPriceMinor !== null ? ((float) $buyPriceMinor) / 100.0 : 0.0;
        $buyGhs = $buyPriceMajor * $supplierRate;

        $kgUnit = $this->chargeableKg(
            $itemData['weightKg'] ?? null,
            $itemData['dimensions'] ?? null,
            $volDivisor,
        );
        $totalKg = round($kgUnit * $qty, 2);

        $shipUsd = $this->dhlCost($totalKg, $parameters['dhlBands'] ?? [], $dhlMin);
        $shipUnit = $qty > 0 ? ($shipUsd * $dhlRate) / $qty : 0.0;

        $insurance = $this->pAmt(
            (string) ($parameters['insuranceBasis'] ?? 'pct'),
            (float) ($parameters['insurancePct'] ?? 0),
            (float) ($parameters['insuranceAmt'] ?? 0),
            $buyGhs + $shipUnit,
        );
        $cif = $buyGhs + $shipUnit + $insurance;

        $duty = $this->pAmt(
            (string) ($parameters['dutyBasis'] ?? 'pct'),
            (float) ($parameters['dutyPct'] ?? 0),
            (float) ($parameters['dutyAmt'] ?? 0),
            $cif,
        );
        $clearance = $this->pAmt(
            (string) ($parameters['clearanceBasis'] ?? 'pct'),
            (float) ($parameters['clearancePct'] ?? 0),
            (float) ($parameters['clearanceAmt'] ?? 0),
            $cif,
        );

        $extras = 0.0;
        foreach ($parameters['extraCosts'] ?? [] as $extra) {
            if (! is_array($extra)) {
                continue;
            }
            $amount = (float) ($extra['amount'] ?? 0);
            $per = (string) ($extra['per'] ?? 'unit');
            $extras += $per === 'order' ? $amount / $qty : $amount;
        }

        $landed = $cif + $duty + $clearance + $extras;

        $contingency = 0.0;
        if ($estimate) {
            $contingency = $this->pAmt(
                (string) ($parameters['contingencyBasis'] ?? 'pct'),
                (float) ($parameters['contingencyPct'] ?? 0),
                (float) ($parameters['contingencyAmt'] ?? 0),
                $landed,
            );
        }

        $markup = $this->pAmt(
            (string) ($parameters['markupBasis'] ?? 'pct'),
            (float) ($parameters['markupPct'] ?? 0),
            (float) ($parameters['markupAmt'] ?? 0),
            $landed + $contingency,
        );

        $price = $landed + $contingency + $markup;

        return [
            'rate' => $supplierRate,
            'buyGhs' => round($buyGhs, 4),
            'kgUnit' => round($kgUnit, 2),
            'totalKg' => $totalKg,
            'shipUsd' => round($shipUsd, 2),
            'shipUnit' => round($shipUnit, 4),
            'insurance' => round($insurance, 4),
            'cif' => round($cif, 4),
            'duty' => round($duty, 4),
            'clearance' => round($clearance, 4),
            'extras' => round($extras, 4),
            'landed' => round($landed, 4),
            'contingency' => round($contingency, 4),
            'markup' => round($markup, 4),
            'price' => round($price, 2),
            'markupPct' => (float) ($parameters['markupPct'] ?? 0),
            'estimate' => $estimate,
            'qty' => $qty,
            'asOfDate' => $asOfDate,
        ];
    }

    /**
     * Chargeable kg = max(actual weight, volumetric weight).
     *
     * @param  array<int, float>|null  $dimensions  [length, width, height] in cm
     */
    public function chargeableKg(?float $weightKg, ?array $dimensions, float $volDivisor): float
    {
        $vol = 0.0;
        if (is_array($dimensions) && count($dimensions) >= 3 && $volDivisor > 0) {
            $vol = ((float) $dimensions[0] * (float) $dimensions[1] * (float) $dimensions[2]) / $volDivisor;
        }

        return max($weightKg ?? 0.0, round($vol, 2));
    }

    /**
     * @param  array<int, array<string, mixed>>  $bands
     */
    public function dhlCost(float $totalKg, array $bands, float $dhlMin): float
    {
        if ($totalKg <= 0) {
            return 0.0;
        }

        $band = null;
        foreach ($bands as $b) {
            if (! is_array($b)) {
                continue;
            }
            $upTo = $b['upTo'] ?? null;
            if ($upTo === null || $totalKg <= (float) $upTo) {
                $band = $b;
                break;
            }
        }

        if ($band === null) {
            return $dhlMin;
        }

        $rate = (float) ($band['rate'] ?? 0);
        $cost = round($totalKg * $rate, 2);

        return max($dhlMin, $cost);
    }

    private function pAmt(string $basis, float $pct, float $amt, float $base): float
    {
        return $basis === 'amt' ? $amt : $base * $pct / 100.0;
    }

    /**
     * Latest mid rate for the currency on or before $date. Returns 1.0 for GHS or when missing.
     */
    private function fxRate(string $companyId, string $currency, string $date): float
    {
        if ($currency === '' || $currency === 'GHS' || $companyId === '') {
            return 1.0;
        }

        $rate = ExchangeRate::query()
            ->where('company_id', $companyId)
            ->where('currency', $currency)
            ->where('rate_date', '<=', $date)
            ->orderByDesc('rate_date')
            ->first();

        if ($rate === null || $rate->mid_rate === null) {
            return 1.0;
        }

        return (float) $rate->mid_rate;
    }
}
