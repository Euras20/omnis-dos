<?php

declare(strict_types=1);

namespace App\Http\Requests\Receivables;

use Illuminate\Foundation\Http\FormRequest;

final class StoreReceiptRequest extends FormRequest
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
            'date' => ['required', 'date_format:Y-m-d'],
            'cashMinor' => ['required', 'integer', 'min:0'],
            'whtMinor' => ['nullable', 'integer', 'min:0'],
            'whtCertificate' => ['nullable', 'string', 'max:128'],
            'cashAccount' => ['nullable', 'string', 'max:32'],
            'bankRef' => ['nullable', 'string', 'max:128'],
            'memo' => ['nullable', 'string', 'max:2000'],
            'allocations' => ['nullable', 'array'],
            'allocations.*.invoiceId' => ['required_with:allocations', 'uuid'],
            'allocations.*.amountMinor' => ['required_with:allocations', 'integer', 'min:1'],
        ];
    }
}
