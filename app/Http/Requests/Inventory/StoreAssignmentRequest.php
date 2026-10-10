<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

final class StoreAssignmentRequest extends FormRequest
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
            'itemId' => ['required', 'uuid'],
            'locationId' => ['required', 'uuid'],
            'lot' => ['nullable', 'string', 'max:64'],
            'qty' => ['required', 'numeric', 'gt:0'],
            'salesOrderLineId' => ['nullable', 'uuid'],
            'memo' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
