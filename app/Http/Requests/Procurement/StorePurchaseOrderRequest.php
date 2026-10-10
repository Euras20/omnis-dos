<?php

declare(strict_types=1);

namespace App\Http\Requests\Procurement;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StorePurchaseOrderRequest extends FormRequest
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
            'supplierId' => ['required', 'uuid'],
            'orderDate' => ['required', 'date_format:Y-m-d'],
            'expectedDate' => ['nullable', 'date_format:Y-m-d'],
            'currency' => ['nullable', 'string', 'size:3'],
            'memo' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.description' => ['nullable', 'string', 'max:512'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
            'lines.*.unitPriceMinor' => ['required', 'integer', 'min:0'],
            'lines.*.taxMinor' => ['nullable', 'integer', 'min:0'],
            'lines.*.itemId' => ['nullable', 'uuid'],
            'lines.*.unit' => ['nullable', 'string', 'max:32'],
            'lines.*.salesOrderLineId' => ['nullable', 'uuid'],
            'lines.*.lineCategory' => ['nullable', 'string', Rule::in(['stocking', 'direct', 'import'])],
        ];
    }
}
