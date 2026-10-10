<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

final class StoreDeliveryRequest extends FormRequest
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
            'assignmentId' => ['required', 'uuid'],
            'deliveryDate' => ['required', 'date_format:Y-m-d'],
            'memo' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
