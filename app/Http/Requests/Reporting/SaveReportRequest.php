<?php

declare(strict_types=1);

namespace App\Http\Requests\Reporting;

use Illuminate\Foundation\Http\FormRequest;

class SaveReportRequest extends FormRequest
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
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'key' => ['required', 'string', 'max:128'],
            'period' => ['sometimes', 'string', 'max:32'],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3'],
            'shared' => ['sometimes', 'boolean'],
            'settings' => ['sometimes', 'array'],
        ];
    }
}
