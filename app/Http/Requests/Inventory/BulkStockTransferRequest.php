<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

final class BulkStockTransferRequest extends FormRequest
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
            'fromLocationId' => ['nullable', 'uuid'],
            'toLocationId' => ['nullable', 'uuid'],
            'transferDate' => ['nullable', 'date_format:Y-m-d'],
            'memo' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.itemId' => ['required', 'uuid'],
            'lines.*.fromLocationId' => ['nullable', 'uuid'],
            'lines.*.toLocationId' => ['nullable', 'uuid'],
            'lines.*.lot' => ['nullable', 'string', 'max:64'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
            'lines.*.transferDate' => ['nullable', 'date_format:Y-m-d'],
            'lines.*.memo' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
