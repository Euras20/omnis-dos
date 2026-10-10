<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ChangeQuotationStatusRequest extends FormRequest
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
            'status' => ['required', 'string', Rule::in(['sent', 'accepted', 'declined', 'expired'])],
        ];
    }
}
