<?php

declare(strict_types=1);

namespace App\Http\Requests\Quality;

use Illuminate\Foundation\Http\FormRequest;

final class SendToCorrectiveActionRequest extends FormRequest
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
            'comments' => ['required_without:action', 'nullable', 'string', 'max:4000'],
            'action' => ['required_without:comments', 'nullable', 'string', 'max:4000'],
            'dueDate' => ['nullable', 'date'],
            'ownerId' => ['nullable', 'uuid'],
            'verifierId' => ['nullable', 'uuid'],
        ];
    }
}
