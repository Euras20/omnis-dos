<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;

final class StoreTaxRuleRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:255'],
            'effectiveFrom' => ['required', 'date'],
            'effectiveTo' => ['nullable', 'date', 'after_or_equal:effectiveFrom'],
            'ratePercent' => ['required_without:rate', 'numeric', 'min:0', 'max:100'],
            'rate' => ['required_without:ratePercent', 'numeric', 'min:0', 'max:100'],
            'components' => ['nullable'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
