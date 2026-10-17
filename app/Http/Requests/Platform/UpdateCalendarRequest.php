<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCalendarRequest extends FormRequest
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
            'workingDaysFactory' => ['sometimes', 'array'],
            'workingDaysFactory.*' => ['string', 'in:Monday,Tuesday,Wednesday,Thursday,Friday,Saturday,Sunday'],
            'workingDaysOffice' => ['sometimes', 'array'],
            'workingDaysOffice.*' => ['string', 'in:Monday,Tuesday,Wednesday,Thursday,Friday,Saturday,Sunday'],
            'shifts' => ['sometimes', 'array'],
            'shifts.*.name' => ['required_with:shifts', 'string', 'max:64'],
            'shifts.*.start' => ['required_with:shifts', 'string', 'regex:/^\d{2}:\d{2}$/'],
            'shifts.*.end' => ['required_with:shifts', 'string', 'regex:/^\d{2}:\d{2}$/'],
        ];
    }
}
