<?php

declare(strict_types=1);

namespace App\Http\Requests\Logistics;

use Illuminate\Foundation\Http\FormRequest;

final class StoreEtaRevisionRequest extends FormRequest
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
            'shipmentId' => ['nullable', 'uuid'],
            'salesOrderId' => ['nullable', 'uuid'],
            'originalPromise' => ['nullable', 'date_format:Y-m-d'],
            'previous' => ['nullable', 'date_format:Y-m-d'],
            'revised' => ['required', 'date_format:Y-m-d'],
            'source' => ['nullable', 'string', 'max:64'],
            'confidence' => ['nullable', 'string', 'max:32'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'revisedBy' => ['nullable', 'string', 'max:128'],
        ];
    }
}
