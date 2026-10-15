<?php

declare(strict_types=1);

namespace App\Http\Requests\Logistics;

use Illuminate\Foundation\Http\FormRequest;

final class RecordShipmentEventRequest extends FormRequest
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
            'eventType' => ['required', 'string', 'max:64'],
            'location' => ['required', 'string', 'max:128'],
            'consignmentId' => ['nullable', 'uuid'],
            'qtyAffected' => ['nullable', 'numeric', 'min:0'],
            'source' => ['nullable', 'string', 'max:255'],
        ];
    }
}
