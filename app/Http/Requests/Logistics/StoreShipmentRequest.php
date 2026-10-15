<?php

declare(strict_types=1);

namespace App\Http\Requests\Logistics;

use Illuminate\Foundation\Http\FormRequest;

final class StoreShipmentRequest extends FormRequest
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
            'kind' => ['nullable', 'string', 'max:32'],
            'transport' => ['nullable', 'string', 'max:16'],
            'mode' => ['nullable', 'string', 'max:64'],
            'booking' => ['nullable', 'string', 'max:64'],
            'incoterm' => ['nullable', 'string', 'max:64'],
            'shipperId' => ['nullable', 'uuid'],
            'consignee' => ['nullable', 'string', 'max:255'],
            'carrierId' => ['nullable', 'uuid'],
            'forwarderId' => ['nullable', 'uuid'],
            'insurance' => ['nullable', 'string', 'max:255'],
            'owner' => ['nullable', 'string', 'max:128'],
            'note' => ['nullable', 'string', 'max:2000'],
            'origin' => ['nullable', 'string', 'max:128'],
            'consignments' => ['required', 'array', 'min:1'],
            'consignments.*.purchaseOrderLineId' => ['nullable', 'uuid'],
            'consignments.*.itemId' => ['nullable', 'uuid'],
            'consignments.*.qty' => ['required', 'numeric', 'gt:0'],
            'consignments.*.container' => ['nullable', 'string', 'max:64'],
            'consignments.*.seal' => ['nullable', 'string', 'max:64'],
            'consignments.*.bl' => ['nullable', 'string', 'max:64'],
            'consignments.*.packages' => ['nullable', 'integer', 'min:0'],
            'consignments.*.weightKg' => ['nullable', 'numeric', 'min:0'],
            'consignments.*.volumeM3' => ['nullable', 'numeric', 'min:0'],
            'consignments.*.eta' => ['nullable', 'date_format:Y-m-d'],
            'consignments.*.legs' => ['nullable', 'array'],
            'consignments.*.legs.*.seq' => ['nullable', 'integer', 'min:1'],
            'consignments.*.legs.*.from' => ['required_with:consignments.*.legs', 'string', 'max:128'],
            'consignments.*.legs.*.to' => ['required_with:consignments.*.legs', 'string', 'max:128'],
            'consignments.*.legs.*.carrierId' => ['nullable', 'uuid'],
            'consignments.*.legs.*.vessel' => ['nullable', 'string', 'max:128'],
            'consignments.*.legs.*.plannedDep' => ['nullable', 'date_format:Y-m-d'],
            'consignments.*.legs.*.plannedArr' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
