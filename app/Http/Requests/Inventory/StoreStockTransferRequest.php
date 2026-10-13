<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

final class StoreStockTransferRequest extends FormRequest
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
            'fromLocationId' => ['required', 'uuid'],
            'toLocationId' => ['required', 'uuid'],
            'lot' => ['nullable', 'string', 'max:64'],
            'qty' => ['required', 'numeric', 'gt:0'],
            'transferDate' => ['required', 'date_format:Y-m-d'],
            'memo' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
