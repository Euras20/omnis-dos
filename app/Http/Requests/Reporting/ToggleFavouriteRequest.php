<?php

declare(strict_types=1);

namespace App\Http\Requests\Reporting;

use Illuminate\Foundation\Http\FormRequest;

class ToggleFavouriteRequest extends FormRequest
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
            'reportKey' => ['required', 'string', 'max:128'],
        ];
    }
}
