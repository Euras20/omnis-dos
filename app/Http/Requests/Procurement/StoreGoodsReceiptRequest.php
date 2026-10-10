<?php

declare(strict_types=1);

namespace App\Http\Requests\Procurement;

use Illuminate\Foundation\Http\FormRequest;

final class StoreGoodsReceiptRequest extends FormRequest
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
            'purchaseOrderId' => ['required', 'uuid'],
            'locationId' => ['required', 'uuid'],
            'receiptDate' => ['required', 'date_format:Y-m-d'],
            'memo' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.purchaseOrderLineId' => ['required', 'uuid'],
            'lines.*.qtyReceived' => ['required', 'numeric', 'gt:0'],
            'lines.*.unitCostMinor' => ['required', 'integer', 'min:0'],
            'lines.*.lot' => ['nullable', 'string', 'max:64'],
        ];
    }
}
