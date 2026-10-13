<?php

declare(strict_types=1);

namespace App\Http\Requests\Payables;

use Illuminate\Foundation\Http\FormRequest;

final class StoreBillRequest extends FormRequest
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
            'billDate' => ['required', 'date_format:Y-m-d'],
            'dueDate' => ['nullable', 'date_format:Y-m-d'],
            'supplierRef' => ['nullable', 'string', 'max:128'],
            'netMinor' => ['required', 'integer', 'min:0'],
            'taxMinor' => ['nullable', 'integer', 'min:0'],
            'grossMinor' => ['nullable', 'integer', 'min:1'],
            'expenseAccountId' => ['nullable', 'uuid'],
            'expenseAccountCode' => ['nullable', 'string', 'max:32'],
            'memo' => ['nullable', 'string', 'max:2000'],
            'purchaseOrderId' => ['nullable', 'uuid'],
            'currency' => ['nullable', 'string', 'size:3'],
            'status' => ['nullable', 'string', 'in:posted,mismatch'],
            'lines' => ['nullable', 'array'],
            'lines.*.purchaseOrderLineId' => ['nullable', 'uuid'],
            'lines.*.itemId' => ['nullable', 'uuid'],
            'lines.*.description' => ['nullable', 'string', 'max:512'],
            'lines.*.qty' => ['nullable', 'numeric', 'min:0'],
            'lines.*.unit' => ['nullable', 'string', 'max:32'],
            'lines.*.unitPriceMinor' => ['nullable', 'integer', 'min:0'],
            'lines.*.netMinor' => ['nullable', 'integer', 'min:0'],
            'lines.*.taxMinor' => ['nullable', 'integer', 'min:0'],
            'lines.*.grossMinor' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
