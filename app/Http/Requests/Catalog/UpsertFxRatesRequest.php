<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;

final class UpsertFxRatesRequest extends FormRequest
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
            'source' => ['nullable', 'string', 'max:64'],
            'rates' => ['required', 'array', 'min:1'],
            'rates.*.currency' => ['required', 'string', 'size:3'],
            'rates.*.buyRate' => ['required_without:rates.*.buy', 'numeric', 'min:0'],
            'rates.*.sellRate' => ['required_without:rates.*.sell', 'numeric', 'min:0'],
            'rates.*.buy' => ['required_without:rates.*.buyRate', 'numeric', 'min:0'],
            'rates.*.sell' => ['required_without:rates.*.sellRate', 'numeric', 'min:0'],
            'rates.*.midRate' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
