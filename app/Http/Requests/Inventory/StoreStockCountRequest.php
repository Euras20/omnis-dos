<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

final class StoreStockCountRequest extends FormRequest
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
            'locationId' => ['required', 'uuid'],
            'countDate' => ['required', 'date_format:Y-m-d'],
            'memo' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.itemId' => ['required', 'uuid'],
            'lines.*.lot' => ['nullable', 'string', 'max:64'],
            'lines.*.bookQty' => ['nullable', 'numeric'],
            'lines.*.countedQty' => ['required', 'numeric'],
            'lines.*.reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
