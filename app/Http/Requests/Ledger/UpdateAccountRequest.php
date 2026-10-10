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
            'category' => ['sometimes', 'string', 'in:asset,liability,equity,income,expense'],
            'kind' => ['sometimes', 'string', 'in:header,detail'],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3'],
            'isActive' => ['sometimes', 'boolean'],
            'fsLineItem' => ['sometimes', 'nullable', 'string', 'max:64'],
            'fsSubLine' => ['sometimes', 'nullable', 'string', 'max:64'],
            'normalBalance' => ['sometimes', 'string', 'in:debit,credit'],
            'rowVersion' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
