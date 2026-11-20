<?php

declare(strict_types=1);

namespace App\Http\Requests\Ledger;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateAccountRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:255'],
            'code' => ['sometimes', 'nullable', 'string', 'max:32'],
            'description' => ['sometimes', 'nullable', 'string', 'max:512'],
            'category' => ['sometimes', 'string', 'in:asset,liability,equity,income,expense'],
            'detailType' => ['sometimes', 'nullable', 'string', 'max:80'],
            'parentId' => ['sometimes', 'nullable', 'uuid'],
            'kind' => ['sometimes', 'string', 'in:header,detail'],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3'],
            'isActive' => ['sometimes', 'boolean'],
            'fsLineItem' => ['sometimes', 'nullable', 'string', 'max:64'],
            'fsSubLine' => ['sometimes', 'nullable', 'string', 'max:64'],
            'normalBalance' => ['sometimes', 'string', 'in:debit,credit'],
            'reportSection' => ['sometimes', 'nullable', 'string', 'max:128'],
            'rowVersion' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
