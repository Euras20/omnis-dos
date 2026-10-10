<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class DecideAssignmentRequest extends FormRequest
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
            'decision' => ['required', 'string', Rule::in(['confirm', 'reject'])],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
