<?php

declare(strict_types=1);

namespace App\Http\Requests\Ledger;

use Illuminate\Foundation\Http\FormRequest;

final class StoreJournalRequest extends FormRequest
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
            'postingDate' => ['required', 'date_format:Y-m-d'],
            'description' => ['sometimes', 'nullable', 'string', 'max:512'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.accountId' => ['required', 'uuid'],
            'lines.*.debitMinor' => ['sometimes', 'integer', 'min:0'],
            'lines.*.creditMinor' => ['sometimes', 'integer', 'min:0'],
            'lines.*.partyId' => ['sometimes', 'nullable', 'uuid'],
            'lines.*.memo' => ['sometimes', 'nullable', 'string', 'max:512'],
            'lines.*.dimension1' => ['sometimes', 'nullable', 'string', 'max:64'],
            'lines.*.dimension2' => ['sometimes', 'nullable', 'string', 'max:64'],
        ];
    }
}
