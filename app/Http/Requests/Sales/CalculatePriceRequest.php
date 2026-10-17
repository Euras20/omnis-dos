<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

final class CalculatePriceRequest extends FormRequest
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
            'itemId' => ['nullable', 'string', 'max:64'],
            'sku' => ['nullable', 'string', 'max:64'],
            'qty' => ['nullable', 'integer', 'min:1'],
            'estimate' => ['nullable', 'boolean'],
            'asOfDate' => ['nullable', 'date_format:Y-m-d'],
            'buyPriceMinor' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
