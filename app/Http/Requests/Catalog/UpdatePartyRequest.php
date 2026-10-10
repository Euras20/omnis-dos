<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;

final class UpdatePartyRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:255'],
            'tin' => ['nullable', 'string', 'max:64'],
            'regNo' => ['nullable', 'string', 'max:64'],
            'city' => ['nullable', 'string', 'max:128'],
            'region' => ['nullable', 'string', 'max:128'],
            'country' => ['nullable', 'string', 'max:64'],
            'address' => ['nullable', 'string'],
            'gps' => ['nullable', 'string', 'max:64'],
            'contactName' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:64'],
            'paymentTerms' => ['nullable', 'string', 'max:128'],
            'creditLimitMinor' => ['nullable', 'integer', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'vatRegistered' => ['nullable', 'boolean'],
            'whtRuleCode' => ['nullable', 'string', 'max:64'],
            'accountOwner' => ['nullable', 'string', 'max:255'],
            'isMainSupplier' => ['nullable', 'boolean'],
            'contacts' => ['nullable', 'array'],
            'bankDetails' => ['nullable', 'array'],
            'isActive' => ['nullable', 'boolean'],
        ];
    }
}
