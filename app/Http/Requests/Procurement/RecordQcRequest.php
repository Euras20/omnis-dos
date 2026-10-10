<?php

declare(strict_types=1);

namespace App\Http\Requests\Procurement;

use Illuminate\Foundation\Http\FormRequest;

final class RecordQcRequest extends FormRequest
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
            'qtyCertified' => ['nullable', 'numeric', 'min:0'],
            'qtyQuarantine' => ['nullable', 'numeric', 'min:0'],
            'qtyRejected' => ['nullable', 'numeric', 'min:0'],
            'storeLocationId' => ['nullable', 'uuid'],
            'qcNote' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
