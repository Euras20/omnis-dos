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
            'itemType' => ['sometimes', 'nullable', 'string', 'max:64'],
            'type' => ['sometimes', 'nullable', 'string', 'max:64'],
            // Code string (e.g. "RAW") or omit. Explicit null clears category.
            'categoryCode' => ['sometimes', 'nullable', 'string', 'max:64'],
            // Alternative: set category by id (resolved to category_code server-side).
            'categoryId' => ['sometimes', 'nullable', 'uuid'],
            'unit' => ['sometimes', 'nullable', 'string', 'max:32'],
            'listPriceMinor' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'priceMinor' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'standardCostMinor' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'costMinor' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'buyPriceMinor' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'weightKg' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'weight' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'dimensions' => ['sometimes', 'nullable', 'array'],
            'defaultLocationId' => ['sometimes', 'nullable', 'uuid'],
            'defaultSupplierId' => ['sometimes', 'nullable', 'uuid'],
            'inventoryPolicy' => ['sometimes', 'nullable', 'string', 'max:32'],
            'policy' => ['sometimes', 'nullable', 'string', 'max:32'],
            'lotControlled' => ['sometimes', 'nullable', 'boolean'],
            'lotCtl' => ['sometimes', 'nullable', 'boolean'],
            'reorderPoint' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'reorder' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'safetyStock' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'safety' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'inventoryAccountCode' => ['sometimes', 'nullable', 'string', 'max:32'],
            'isActive' => ['sometimes', 'nullable', 'boolean'],
        ];
    }
}
