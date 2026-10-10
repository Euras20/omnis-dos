<?php

declare(strict_types=1);

namespace App\Http\Requests\Identity;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateRoleRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:128'],
            'description' => ['nullable', 'string', 'max:2000'],
            'approvalLimitMinor' => ['nullable', 'integer', 'min:0'],
            'permissions' => ['required', 'array'],
            'permissions.*' => ['string', 'max:128'],
            'rowVersion' => ['required', 'integer', 'min:1'],
        ];
    }
}
