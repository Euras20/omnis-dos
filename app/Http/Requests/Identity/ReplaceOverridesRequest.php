<?php

declare(strict_types=1);

namespace App\Http\Requests\Identity;

use Illuminate\Foundation\Http\FormRequest;

final class ReplaceOverridesRequest extends FormRequest
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
            'overrides' => ['required', 'array'],
            'overrides.*.permission' => ['required', 'string', 'max:128'],
            'overrides.*.effect' => ['required', 'string', 'in:grant,deny'],
            'overrides.*.reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
