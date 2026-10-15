<?php

declare(strict_types=1);

namespace App\Http\Requests\Logistics;

use Illuminate\Foundation\Http\FormRequest;

final class StoreCustomsDeclarationRequest extends FormRequest
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
            'jurisdiction' => ['nullable', 'string', 'max:64'],
            'brokerId' => ['nullable', 'uuid'],
            'hsCode' => ['nullable', 'string', 'max:32'],
            'valuation' => ['nullable', 'string', 'max:128'],
            'dutiesMinor' => ['nullable', 'integer', 'min:0'],
            'recoverableTaxMinor' => ['nullable', 'integer', 'min:0'],
            'nonrecoverableMinor' => ['nullable', 'integer', 'min:0'],
            'inspection' => ['nullable', 'string', 'max:64'],
            'freeTimeEnd' => ['nullable', 'date_format:Y-m-d'],
            'demurrageMinor' => ['nullable', 'integer', 'min:0'],
            'docs' => ['nullable', 'array'],
        ];
    }
}
