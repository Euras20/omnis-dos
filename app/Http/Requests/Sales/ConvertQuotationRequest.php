<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

final class ConvertQuotationRequest extends FormRequest
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
            'orderDate' => ['nullable', 'date_format:Y-m-d'],
            'requiredDate' => ['nullable', 'date_format:Y-m-d'],
            'customerPoRef' => ['nullable', 'string', 'max:128'],
            'deliverTo' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
