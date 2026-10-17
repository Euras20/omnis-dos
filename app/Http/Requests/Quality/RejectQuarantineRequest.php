<?php

declare(strict_types=1);

namespace App\Http\Requests\Quality;

use Illuminate\Foundation\Http\FormRequest;

final class RejectQuarantineRequest extends FormRequest
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
            'why' => ['required_without:issue', 'nullable', 'string', 'max:2000'],
            'issue' => ['required_without:why', 'nullable', 'string', 'max:2000'],
            'when' => ['required_without:occurredAt', 'nullable', 'date'],
            'occurredAt' => ['required_without:when', 'nullable', 'date'],
            'action' => ['required', 'string', 'max:2000'],
            'cause' => ['nullable', 'string', 'max:2000'],
            'sku' => ['required_without:itemId', 'nullable', 'string', 'max:64'],
            'itemId' => ['required_without:sku', 'nullable', 'uuid'],
            'qty' => ['required', 'numeric', 'min:0.001'],
            'lot' => ['nullable', 'string', 'max:64'],
            'locationId' => ['nullable', 'uuid'],
            'dueDate' => ['nullable', 'date'],
        ];
    }
}
