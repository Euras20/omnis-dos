<?php

declare(strict_types=1);

namespace App\Http\Requests\Tax;

use Illuminate\Foundation\Http\FormRequest;

final class UpsertGhanaTaxPackageRequest extends FormRequest
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
            'effectiveFrom' => ['required', 'date'],
            'effectiveTo' => ['nullable', 'date', 'after_or_equal:effectiveFrom'],
            'name' => ['nullable', 'string', 'max:255'],
            // Nested package (preferred) or flat S.tax-like fields.
            'package' => ['nullable', 'array'],
            'package.vat' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'package.nhil' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'package.getfund' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'package.covid' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'package.paye' => ['nullable', 'array'],
            'package.paye.*.upTo' => ['nullable', 'numeric'],
            'package.paye.*.rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'package.ssnitEmp' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'package.ssnitEr' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'package.nonResident' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'package.note' => ['nullable', 'string'],
            // Flat shape (frontend S.tax posts these at the top level).
            'vat' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'nhil' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'getfund' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'covid' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'paye' => ['nullable', 'array'],
            'paye.*.upTo' => ['nullable', 'numeric'],
            'paye.*.rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'ssnitEmp' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'ssnitEr' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'nonResident' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'note' => ['nullable', 'string'],
            'method' => ['nullable', 'string', 'max:32'],
            'law' => ['nullable', 'string'],
            'vatThresholdMinor' => ['nullable', 'integer', 'min:0'],
            'ssnitMin' => ['nullable', 'numeric'],
            'ssnitMax' => ['nullable', 'numeric'],
            'tier1' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'tier2' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'otJunior1' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'otJunior2' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'otCapPctBasic' => ['nullable', 'numeric'],
            'bonusRate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'bonusCapPctAnnual' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'cit' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
