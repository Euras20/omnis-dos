<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateItemRequest extends FormRequest
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
            'rowVersion' => ['required', 'integer', 'min:1'],
            'sku' => ['sometimes', 'string', 'max:64'],
            'name' => ['sometimes', 'string', 'max:255'],
            'itemType' => ['nullable', 'string', 'max:64'],
            'type' => ['nullable', 'string', 'max:64'],
            'categoryCode' => ['nullable', 'string', 'max:32'],
            'unit' => ['nullable', 'string', 'max:32'],
            'listPriceMinor' => ['nullable', 'integer', 'min:0'],
            'priceMinor' => ['nullable', 'integer', 'min:0'],
            'standardCostMinor' => ['nullable', 'integer', 'min:0'],
            'costMinor' => ['nullable', 'integer', 'min:0'],
            'buyPriceMinor' => ['nullable', 'integer', 'min:0'],
            'weightKg' => ['nullable', 'numeric', 'min:0'],
            'weight' => ['nullable', 'numeric', 'min:0'],
            'dimensions' => ['nullable', 'array'],
            'defaultLocationId' => ['nullable', 'uuid'],
            'defaultSupplierId' => ['nullable', 'uuid'],
            'inventoryPolicy' => ['nullable', 'string', 'max:32'],
            'policy' => ['nullable', 'string', 'max:32'],
            'lotControlled' => ['nullable', 'boolean'],
            'lotCtl' => ['nullable', 'boolean'],
            'reorderPoint' => ['nullable', 'numeric', 'min:0'],
            'reorder' => ['nullable', 'numeric', 'min:0'],
            'safetyStock' => ['nullable', 'numeric', 'min:0'],
            'safety' => ['nullable', 'numeric', 'min:0'],
            'inventoryAccountCode' => ['nullable', 'string', 'max:32'],
            'isActive' => ['nullable', 'boolean'],
        ];
    }
}
