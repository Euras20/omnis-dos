<?php

declare(strict_types=1);

namespace App\Http\Requests\Fulfillment;

use Illuminate\Foundation\Http\FormRequest;

final class PackDeliveryRequest extends FormRequest
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
            'packages' => ['required', 'string', 'max:128'],
            'weightKg' => ['nullable', 'numeric', 'min:0'],
            'volumeM3' => ['nullable', 'numeric', 'min:0'],
            'packedBy' => ['nullable', 'string', 'max:128'],
        ];
    }
}
