<?php

declare(strict_types=1);

namespace App\Http\Requests\Fulfillment;

use Illuminate\Foundation\Http\FormRequest;

final class InspectGoodsReturnRequest extends FormRequest
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
            'decision' => ['required', 'in:restock,scrap'],
            'locationId' => ['nullable', 'uuid'],
        ];
    }
}
