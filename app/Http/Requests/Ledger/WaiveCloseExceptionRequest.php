<?php

declare(strict_types=1);

namespace App\Http\Requests\Ledger;

use Illuminate\Foundation\Http\FormRequest;

final class WaiveCloseExceptionRequest extends FormRequest
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
            'exceptionCode' => ['required', 'string', 'max:64'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
            'evidenceRef' => ['nullable', 'string', 'max:255'],
        ];
    }
}
