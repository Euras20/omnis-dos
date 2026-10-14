<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateItemCategoryRequest extends FormRequest
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
            'code' => ['sometimes', 'string', 'max:32'],
            'name' => ['sometimes', 'string', 'max:255'],
            'isActive' => ['nullable', 'boolean'],
        ];
    }
}
