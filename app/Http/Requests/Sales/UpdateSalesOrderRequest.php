<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateSalesOrderRequest extends FormRequest
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
            'salesChannel' => ['sometimes', 'nullable', 'string', 'in:wholesale,retail'],
            'orderTypeId' => ['sometimes', 'nullable', 'uuid'],
        ];
    }
}
