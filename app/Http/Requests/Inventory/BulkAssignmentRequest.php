<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

final class BulkAssignmentRequest extends FormRequest
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
            'itemId' => ['nullable', 'uuid'],
            'locationId' => ['nullable', 'uuid'],
            'salesOrderLineId' => ['nullable', 'uuid'],
            'memo' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.itemId' => ['nullable', 'uuid'],
            'lines.*.locationId' => ['nullable', 'uuid'],
            'lines.*.lot' => ['nullable', 'string', 'max:64'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
            'lines.*.salesOrderLineId' => ['nullable', 'uuid'],
            'lines.*.memo' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
