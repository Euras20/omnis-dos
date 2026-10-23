<?php

declare(strict_types=1);

namespace App\Http\Controllers\Treasury;

use App\Modules\Treasury\Actions\CreateBankAccount;
use App\Modules\Treasury\Queries\ListBankAccountsQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class BankAccountController
{
    public function __construct(
        private readonly ListBankAccountsQuery $list,
        private readonly CreateBankAccount $create,
    ) {
    }

    public function index(): JsonResponse
    {
        return response()->json($this->list->execute());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'bankName' => ['required', 'string', 'max:128'],
            'accountName' => ['required', 'string', 'max:255'],
            'accountNumber' => ['required', 'string', 'max:64'],
            'branch' => ['nullable', 'string', 'max:128'],
            'swift' => ['nullable', 'string', 'max:16'],
            'currency' => ['nullable', 'string', 'size:3'],
            'isActive' => ['nullable', 'boolean'],
        ]);

        return response()->json($this->create->execute($data), 201);
    }
}
