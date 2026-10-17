<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services;

use App\Models\Employee;

/**
 * Pure Ghana payroll calculation matching the frontend payCalc.
 *
 * Rules (defaults match S.tax in the review build):
 * - Allowance = 10% of basic.
 * - Overtime: hourly = round(basic / 176) × 1.5.
 * - SSNIT on insurable basic (clamped to min/max), not on gross.
 * - Tier 1 / Tier 2 split from employee + employer SSNIT.
 * - Junior resident staff: OT taxed separately (first portion at otJunior1, excess at otJunior2);
 *   OT is excluded from assessable income.
 * - Senior / non-resident: OT included in assessable; non-resident PAYE is flat.
 * - PAYE bands are widths ("next GHS X at rate Y"), not cumulative ceilings.
 * - Monthly OT above otMonthlyCapHours is an exception unless approved.
 */
final class PayrollCalculator
{
    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    public function calculate(Employee $employee, int $otHours, array $rules, bool $overtimeApproved = false): array
    {
        $basic = (int) $employee->basic_minor;
        $monthlyHours = (int) ($rules['monthlyHours'] ?? 176);
        $otMultiplier = (float) ($rules['otMultiplier'] ?? 1.5);
        $hourly = $monthlyHours > 0 && $basic > 0 ? (int) round($basic / $monthlyHours) : 0;
        $ot = (int) round($otHours * $hourly * $otMultiplier);

        $allowancePct = (float) ($rules['allowancePct'] ?? 10);
        $allowance = (int) round($basic * $allowancePct / 100);
        $gross = $basic + $allowance + $ot;

        $ssnitMin = (int) ($rules['ssnitMinMinor'] ?? 58780);
        $ssnitMax = (int) ($rules['ssnitMaxMinor'] ?? 6900000);
        $insurable = min($ssnitMax, max($ssnitMin, $basic));

        $ssnitEmpPct = (float) ($rules['ssnitEmp'] ?? $rules['ssfEmployeePct'] ?? 5.5);
        $ssnitErPct = (float) ($rules['ssnitEr'] ?? $rules['ssfEmployerPct'] ?? 13.0);
        $empSS = (int) round($insurable * $ssnitEmpPct / 100);
        $erSS = (int) round($insurable * $ssnitErPct / 100);
        $tier1Pct = (float) ($rules['tier1Pct'] ?? 13.5);
        $tier1 = (int) round($insurable * $tier1Pct / 100);
        $tier2 = $empSS + $erSS - $tier1;

        $level = (string) ($employee->level ?? 'Junior Employee');
        $resident = (bool) ($employee->is_resident ?? true);
        $otQualifyMax = $rules['otQualifyMaxBasicMinor'] ?? null;
        $junior = str_starts_with($level, 'Junior')
            && $resident
            && ($otQualifyMax === null || $basic <= (int) $otQualifyMax);

        $otCapPct = (float) ($rules['otCapPctBasic'] ?? 50);
        $otCap = (int) round($basic * $otCapPct / 100);
        $otJunior1 = (float) ($rules['otJunior1'] ?? 5);
        $otJunior2 = (float) ($rules['otJunior2'] ?? 10);
        $otTax = 0;
        if ($junior) {
            $first = min($ot, $otCap);
            $excess = max(0, $ot - $otCap);
            $otTax = (int) round($first * $otJunior1 / 100 + $excess * $otJunior2 / 100);
        }

        $assessable = $basic + $allowance + ($junior ? 0 : $ot);
        $relief = $empSS;
        $taxable = max(0, $assessable - $relief);

        if (! $resident) {
            $nonRes = (float) ($rules['nonResidentPct'] ?? 25);
            $paye = (int) round($taxable * $nonRes / 100);
        } else {
            $paye = $this->computePaye($taxable, $this->normalizeBands($rules['payeBands'] ?? $rules['paye'] ?? []));
        }

        $net = $gross - $empSS - $paye - $otTax;

        $capHours = (int) ($rules['otMonthlyCapHours'] ?? 24);
        $exception = null;
        if ($otHours > $capHours && ! $overtimeApproved) {
            $name = trim($employee->first_name.' '.$employee->last_name);
            $exception = $name.': '.$otHours.' overtime hours exceed '.$capHours.' h monthly cap and are not approved';
        }

        return [
            'basicMinor' => $basic,
            'allowancesMinor' => $allowance,
            'overtimeHours' => $otHours,
            'overtimeMinor' => $ot,
            'grossMinor' => $gross,
            'insurableMinor' => $insurable,
            'ssfEmployeeMinor' => $empSS,
            'ssfEmployerMinor' => $erSS,
            'tier1Minor' => $tier1,
            'tier2Minor' => $tier2,
            'assessableMinor' => $assessable,
            'reliefMinor' => $relief,
            'taxableMinor' => $taxable,
            'payeMinor' => $paye,
            'otTaxMinor' => $otTax,
            'netMinor' => $net,
            'isJunior' => $junior,
            'isResident' => $resident,
            'exception' => $exception,
        ];
    }

    /**
     * Default rules matching the frontend S.tax (Ghana 2026 as understood).
     *
     * @return array<string, mixed>
     */
    public function defaultRules(): array
    {
        return [
            'ssnitEmp' => 5.5,
            'ssnitEr' => 13.0,
            'ssnitMinMinor' => 58780,
            'ssnitMaxMinor' => 6900000,
            'tier1Pct' => 13.5,
            'otJunior1' => 5,
            'otJunior2' => 10,
            'otCapPctBasic' => 50,
            'otQualifyMaxBasicMinor' => null,
            'nonResidentPct' => 25,
            'allowancePct' => 10,
            'monthlyHours' => 176,
            'otMultiplier' => 1.5,
            'otMonthlyCapHours' => 24,
            'payeBands' => [
                ['width' => 49000, 'rate' => 0],
                ['width' => 11000, 'rate' => 5],
                ['width' => 13000, 'rate' => 10],
                ['width' => 316667, 'rate' => 17.5],
                ['width' => 1600000, 'rate' => 25],
                ['width' => 3052000, 'rate' => 30],
                ['width' => null, 'rate' => 35],
            ],
        ];
    }

    /**
     * Normalize bands to width form.
     * - Already has 'width' → use as-is.
     * - Decreasing / non-monotonic upTo → treat upTo as width (frontend style).
     * - Increasing absolute upTo → convert to widths.
     *
     * @param  array<int, array<string, mixed>>  $bands
     * @return array<int, array{width: ?int, rate: float|int}>
     */
    private function normalizeBands(array $bands): array
    {
        if ($bands === []) {
            return $this->defaultRules()['payeBands'];
        }

        if (isset($bands[0]['width'])) {
            return array_map(static fn (array $b): array => [
                'width' => $b['width'] === null ? null : (int) $b['width'],
                'rate' => $b['rate'] ?? 0,
            ], $bands);
        }

        $isAbsolute = true;
        $prev = 0;
        foreach ($bands as $b) {
            $up = $b['upTo'] ?? null;
            if ($up !== null && (int) $up < $prev) {
                $isAbsolute = false;
                break;
            }
            if ($up !== null) {
                $prev = (int) $up;
            }
        }

        if (! $isAbsolute) {
            return array_map(static fn (array $b): array => [
                'width' => isset($b['upTo']) && $b['upTo'] !== null ? (int) $b['upTo'] : null,
                'rate' => $b['rate'] ?? 0,
            ], $bands);
        }

        $result = [];
        $prev = 0;
        foreach ($bands as $b) {
            $up = $b['upTo'] ?? null;
            $rate = $b['rate'] ?? 0;
            if ($up === null) {
                $result[] = ['width' => null, 'rate' => $rate];
            } else {
                $result[] = ['width' => (int) $up - $prev, 'rate' => $rate];
                $prev = (int) $up;
            }
        }

        return $result;
    }

    /**
     * Progressive PAYE using band widths (next N at rate).
     *
     * @param  array<int, array{width: ?int, rate: float|int}>  $bands
     */
    private function computePaye(int $taxableMinor, array $bands): int
    {
        if ($taxableMinor <= 0 || $bands === []) {
            return 0;
        }

        $rem = $taxableMinor;
        $tax = 0;

        foreach ($bands as $band) {
            $width = $band['width'] ?? null;
            $rate = (float) ($band['rate'] ?? 0);
            $slice = $width === null ? $rem : min($rem, (int) $width);
            if ($slice <= 0) {
                break;
            }
            $tax += (int) round($slice * $rate / 100);
            $rem -= $slice;
            if ($rem <= 0) {
                break;
            }
        }

        return max(0, $tax);
    }
}
