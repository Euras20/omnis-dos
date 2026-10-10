<?php

declare(strict_types=1);

namespace App\Http\Requests\Identity;

use Illuminate\Foundation\Http\FormRequest;

final class StoreRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission enforced in Action
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/'],
            'name' => ['required', 'string', 'max:128'],
            'description' => ['nullable', 'string', 'max:2000'],
            'approvalLimitMinor' => ['nullable', 'integer', 'min:0'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'max:128'],
            'copyFromCode' => ['nullable', 'string', 'max:64'],
        ];
    }
}
