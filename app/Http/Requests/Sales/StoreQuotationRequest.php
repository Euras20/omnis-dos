<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

final class StoreQuotationRequest extends FormRequest
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
            'customerId' => ['required', 'uuid'],
            'quotationDate' => ['required', 'date_format:Y-m-d'],
            'validUntil' => ['nullable', 'date_format:Y-m-d'],
            'currency' => ['nullable', 'string', 'size:3'],
            'memo' => ['nullable', 'string', 'max:2000'],
            'discountPercent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'deliveryLocation' => ['nullable', 'string', 'max:255'],
            'deliveryType' => ['nullable', 'string', 'max:16'],
            'supplierQuoteRef' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.description' => ['nullable', 'string', 'max:512'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
            'lines.*.unitPriceMinor' => ['required', 'integer', 'min:0'],
            'lines.*.itemId' => ['nullable', 'uuid'],
            'lines.*.unit' => ['nullable', 'string', 'max:32'],
            'lines.*.toOrder' => ['nullable', 'boolean'],
        ];
    }
}
