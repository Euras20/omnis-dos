<?php

declare(strict_types=1);

namespace App\Http\Requests\Fulfillment;

use Illuminate\Foundation\Http\FormRequest;

final class DispatchDeliveryRequest extends FormRequest
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
            'vehicle' => ['nullable', 'string', 'max:64'],
            'driver' => ['nullable', 'string', 'max:128'],
            'carrier' => ['nullable', 'string', 'max:128'],
            'route' => ['nullable', 'string', 'max:255'],
        ];
    }
}
