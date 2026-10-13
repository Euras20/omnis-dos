<?php

declare(strict_types=1);

namespace App\Http\Requests\Receivables;

use Illuminate\Foundation\Http\FormRequest;

final class StoreInvoiceRequest extends FormRequest
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
            'invoiceDate' => ['required', 'date_format:Y-m-d'],
            'dueDate' => ['nullable', 'date_format:Y-m-d'],
            'currency' => ['nullable', 'string', 'size:3'],
            'memo' => ['nullable', 'string', 'max:2000'],
            'salesOrderId' => ['nullable', 'uuid'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.description' => ['required', 'string', 'max:512'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
            'lines.*.unitPriceMinor' => ['required', 'integer', 'min:0'],
            'lines.*.itemId' => ['nullable', 'uuid'],
            'lines.*.unit' => ['nullable', 'string', 'max:32'],
            // Required when salesOrderId is set — links invoice line → order line and updates qty_invoiced.
            'lines.*.salesOrderLineId' => ['nullable', 'uuid'],
        ];
    }
}
