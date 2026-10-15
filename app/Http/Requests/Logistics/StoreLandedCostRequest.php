<?php

declare(strict_types=1);

namespace App\Http\Requests\Logistics;

use Illuminate\Foundation\Http\FormRequest;

final class StoreLandedCostRequest extends FormRequest
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
            'shipmentId' => ['required', 'uuid'],
            'consignmentId' => ['nullable', 'uuid'],
            'category' => ['required', 'string', 'max:64'],
            'description' => ['nullable', 'string', 'max:255'],
            'amountMinor' => ['required', 'integer', 'min:1'],
            'currency' => ['nullable', 'string', 'size:3'],
            'recoverable' => ['nullable', 'boolean'],
            'status' => ['nullable', 'string', 'in:Committed,Actual'],
            'source' => ['nullable', 'string', 'max:128'],
        ];
    }
}
