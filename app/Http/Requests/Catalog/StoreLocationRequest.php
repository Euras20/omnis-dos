<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;

final class StoreLocationRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:32'],
            'name' => ['required', 'string', 'max:255'],
            'locType' => ['required_without:type', 'string', 'in:receiving,quarantine,rejected,store,dispatch'],
            'type' => ['required_without:locType', 'string', 'in:receiving,quarantine,rejected,store,dispatch'],
            'warehouse' => ['nullable', 'string', 'max:128'],
            'keeperId' => ['nullable', 'uuid'],
            'isAssignable' => ['nullable', 'boolean'],
            'assignable' => ['nullable', 'boolean'],
            'capacity' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
