<?php

declare(strict_types=1);

namespace App\Http\Requests\Ledger;

use Illuminate\Foundation\Http\FormRequest;

final class BulkUpdateAccountsRequest extends FormRequest
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
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'uuid'],
            'items.*.name' => ['sometimes', 'string', 'max:255'],
            'items.*.description' => ['sometimes', 'nullable', 'string', 'max:512'],
            'items.*.detailType' => ['sometimes', 'nullable', 'string', 'max:80'],
            'items.*.isActive' => ['sometimes', 'boolean'],
        ];
    }
}
