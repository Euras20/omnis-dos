<?php

declare(strict_types=1);

namespace App\Http\Requests\Ledger;

use Illuminate\Foundation\Http\FormRequest;

final class StoreAccountRequest extends FormRequest
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
            'code' => ['sometimes', 'nullable', 'string', 'max:32'],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['sometimes', 'string', 'in:asset,liability,equity,income,expense'],
            'kind' => ['sometimes', 'string', 'in:header,detail'],
            'nodeType' => ['sometimes', 'string', 'in:GROUP,POSTING,group,posting'],
            'parentId' => ['sometimes', 'nullable', 'uuid'],
            'isContra' => ['sometimes', 'boolean'],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3'],
            'fsLineItem' => ['sometimes', 'nullable', 'string', 'max:64'],
            'fsSubLine' => ['sometimes', 'nullable', 'string', 'max:64'],
            'normalBalance' => ['sometimes', 'string', 'in:debit,credit'],
        ];
    }
}
