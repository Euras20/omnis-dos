<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

final class UpsertPricingParametersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'supplierCur' => ['nullable', 'string', 'size:3'],
            'dhlCur' => ['nullable', 'string', 'size:3'],
            'volDivisor' => ['nullable', 'numeric', 'gt:0'],
            'dhlMin' => ['nullable', 'numeric', 'min:0'],
            'dutyBasis' => ['nullable', 'in:pct,amt'],
            'dutyPct' => ['nullable', 'numeric', 'min:0'],
            'dutyAmt' => ['nullable', 'numeric', 'min:0'],
            'insuranceBasis' => ['nullable', 'in:pct,amt'],
            'insurancePct' => ['nullable', 'numeric', 'min:0'],
            'insuranceAmt' => ['nullable', 'numeric', 'min:0'],
            'clearanceBasis' => ['nullable', 'in:pct,amt'],
            'clearancePct' => ['nullable', 'numeric', 'min:0'],
            'clearanceAmt' => ['nullable', 'numeric', 'min:0'],
            'markupBasis' => ['nullable', 'in:pct,amt'],
            'markupPct' => ['nullable', 'numeric', 'min:0'],
            'markupAmt' => ['nullable', 'numeric', 'min:0'],
            'contingencyBasis' => ['nullable', 'in:pct,amt'],
            'contingencyPct' => ['nullable', 'numeric', 'min:0'],
            'contingencyAmt' => ['nullable', 'numeric', 'min:0'],
            'extraCosts' => ['nullable', 'array'],
            'extraCosts.*.name' => ['required_with:extraCosts', 'string', 'max:255'],
            'extraCosts.*.amount' => ['required_with:extraCosts', 'numeric', 'min:0'],
            'extraCosts.*.per' => ['nullable', 'in:unit,order'],
            'dhlBands' => ['nullable', 'array'],
            'dhlBands.*.upTo' => ['nullable', 'numeric', 'min:0'],
            'dhlBands.*.rate' => ['required_with:dhlBands', 'numeric', 'gt:0'],
        ];
    }
}
