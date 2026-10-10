<?php

declare(strict_types=1);

namespace App\Http\Requests\Payables;

use Illuminate\Foundation\Http\FormRequest;

final class StoreSupplierPaymentRequest extends FormRequest
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
            'whtMinor' => ['nullable', 'integer', 'min:0'],
            'cashAccount' => ['nullable', 'string', 'max:32'],
            'memo' => ['nullable', 'string', 'max:2000'],
            'allocations' => ['required', 'array', 'min:1'],
            'allocations.*.billId' => ['required', 'uuid'],
            'allocations.*.amountMinor' => ['required', 'integer', 'min:1'],
        ];
    }
}
