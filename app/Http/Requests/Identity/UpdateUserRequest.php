<?php

declare(strict_types=1);

namespace App\Http\Requests\Identity;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateUserRequest extends FormRequest
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
            'displayName' => ['sometimes', 'string', 'max:255'],
            'jobTitle' => ['nullable', 'string', 'max:128'],
            'phone' => ['nullable', 'string', 'max:64'],
            'roleCodes' => ['sometimes', 'array'],
            'roleCodes.*' => ['string', 'max:64'],
            'locationIds' => ['sometimes', 'array'],
            'locationIds.*' => ['uuid'],
            'isActive' => ['sometimes', 'boolean'],
            'deactivationReason' => ['nullable', 'string', 'max:2000'],
            'rowVersion' => ['required', 'integer', 'min:1'],
        ];
    }
}
