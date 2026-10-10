<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

final class StoreSalesOrderRequest extends FormRequest
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
            'orderDate' => ['required', 'date_format:Y-m-d'],
            'requiredDate' => ['nullable', 'date_format:Y-m-d'],
            'customerPoRef' => ['nullable', 'string', 'max:128'],
            'orderTypeId' => ['nullable', 'uuid'],
            'quotationId' => ['nullable', 'uuid'],
            'currency' => ['nullable', 'string', 'size:3'],
            'memo' => ['nullable', 'string', 'max:2000'],
            'deliverTo' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.description' => ['nullable', 'string', 'max:512'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
            'lines.*.unitPriceMinor' => ['required', 'integer', 'min:0'],
            'lines.*.itemId' => ['nullable', 'uuid'],
            'lines.*.unit' => ['nullable', 'string', 'max:32'],
        ];
    }
}
