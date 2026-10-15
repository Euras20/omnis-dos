<?php

declare(strict_types=1);

namespace App\Http\Requests\Fulfillment;

use Illuminate\Foundation\Http\FormRequest;

final class StoreDeliveryNoteRequest extends FormRequest
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
            'salesOrderId' => ['required', 'uuid'],
            'plannedDate' => ['required', 'date_format:Y-m-d'],
            'assignmentIds' => ['required', 'array', 'min:1'],
            'assignmentIds.*' => ['required', 'uuid'],
            'route' => ['nullable', 'string', 'max:255'],
            'memo' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
