<?php

declare(strict_types=1);

namespace App\Http\Requests\Fulfillment;

use Illuminate\Foundation\Http\FormRequest;

final class StoreGoodsReturnRequest extends FormRequest
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
            'kind' => ['required', 'in:customer,supplier'],
            'qty' => ['required', 'numeric', 'gt:0'],
            'reason' => ['required', 'string', 'max:2000'],
            'returnDate' => ['nullable', 'date_format:Y-m-d'],
            'deliveryNoteLineId' => ['nullable', 'uuid'],
            'itemId' => ['nullable', 'uuid'],
            'locationId' => ['nullable', 'uuid'],
            'lot' => ['nullable', 'string', 'max:64'],
            'partyId' => ['nullable', 'uuid'],
        ];
    }
}
