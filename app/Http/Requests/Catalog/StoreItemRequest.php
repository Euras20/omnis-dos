<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;

final class StoreItemRequest extends FormRequest
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
            'sku' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:255'],
            'itemType' => ['nullable', 'string', 'max:64'],
            'type' => ['nullable', 'string', 'max:64'],
            'categoryCode' => ['nullable', 'string', 'max:32'],
            'unit' => ['nullable', 'string', 'max:32'],
            'listPriceMinor' => ['nullable', 'integer', 'min:0'],
            'standardCostMinor' => ['nullable', 'integer', 'min:0'],
            'buyPriceMinor' => ['nullable', 'integer', 'min:0'],
            'weightKg' => ['nullable', 'numeric', 'min:0'],
            'dimensions' => ['nullable', 'array'],
            'defaultLocationId' => ['nullable', 'uuid'],
            'defaultSupplierId' => ['nullable', 'uuid'],
            'inventoryPolicy' => ['nullable', 'string', 'max:32'],
            'lotControlled' => ['nullable', 'boolean'],
            'reorderPoint' => ['nullable', 'numeric', 'min:0'],
            'safetyStock' => ['nullable', 'numeric', 'min:0'],
            'inventoryAccountCode' => ['nullable', 'string', 'max:32'],
        ];
    }
}
