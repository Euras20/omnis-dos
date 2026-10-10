<?php

declare(strict_types=1);

namespace App\Http\Requests\Identity;

use Illuminate\Foundation\Http\FormRequest;

final class StoreUserRequest extends FormRequest
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
            'email' => ['required', 'email', 'max:255'],
            'displayName' => ['required', 'string', 'max:255'],
            'userType' => ['nullable', 'string', 'in:staff,portal'],
            'partyId' => ['nullable', 'uuid'],
            'jobTitle' => ['nullable', 'string', 'max:128'],
            'phone' => ['nullable', 'string', 'max:64'],
            'externalSubject' => ['nullable', 'string', 'max:255'],
            'roleCodes' => ['nullable', 'array'],
            'roleCodes.*' => ['string', 'max:64'],
            'locationIds' => ['nullable', 'array'],
            'locationIds.*' => ['uuid'],
        ];
    }
}
