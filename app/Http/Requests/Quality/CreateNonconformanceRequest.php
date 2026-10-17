<?php

declare(strict_types=1);

namespace App\Http\Requests\Quality;

use Illuminate\Foundation\Http\FormRequest;

final class CreateNonconformanceRequest extends FormRequest
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
            'issue' => ['required', 'string', 'max:2000'],
            'why' => ['sometimes', 'string', 'max:2000'],
            'cause' => ['nullable', 'string', 'max:2000'],
            'action' => ['nullable', 'string', 'max:2000'],
            'sourceType' => ['nullable', 'string', 'max:32'],
            'sourceId' => ['nullable', 'uuid'],
            'itemId' => ['nullable', 'uuid'],
            'sku' => ['nullable', 'string', 'max:64'],
            'qty' => ['nullable', 'numeric', 'min:0'],
            'dueDate' => ['nullable', 'date'],
            'occurredAt' => ['nullable', 'date'],
            'when' => ['nullable', 'date'],
            'ownerId' => ['nullable', 'uuid'],
        ];
    }
}
