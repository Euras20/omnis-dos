<?php

declare(strict_types=1);

namespace App\Http\Controllers\Treasury;

use App\Modules\Treasury\Actions\CreateBankAccount;
use App\Modules\Treasury\Actions\PostBankOpeningBalance;
use App\Modules\Treasury\Actions\UpdateBankAccount;
use App\Modules\Treasury\Queries\ListBankAccountsQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class BankAccountController
{
    public function __construct(
        private readonly ListBankAccountsQuery $list,
        private readonly CreateBankAccount $create,
        private readonly UpdateBankAccount $update,
        private readonly PostBankOpeningBalance $postOpening,
    ) {
    }

    public function index(): JsonResponse
    {
        return response()->json($this->list->execute());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['nullable', 'string', 'in:Bank,Mobile money,Petty cash'],
            'glAccountId' => ['nullable', 'string'],
            'glAccountName' => ['nullable', 'string', 'max:255'],
            'bankName' => ['required', 'string', 'max:128'],
            'accountName' => ['required', 'string', 'max:255'],
            'accountNumber' => ['nullable', 'string', 'max:64'],
            'branch' => ['nullable', 'string', 'max:128'],
            'swift' => ['nullable', 'string', 'max:16'],
            'currency' => ['nullable', 'string', 'size:3'],
            'isActive' => ['nullable', 'boolean'],
        ]);

        return response()->json($this->create->execute($data), 201);
    }

    public function update(string $id, Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['nullable', 'string', 'in:Bank,Mobile money,Petty cash'],
            'glAccountId' => ['nullable', 'uuid'],
            'bankName' => ['nullable', 'string', 'max:128'],
            'accountName' => ['nullable', 'string', 'max:255'],
            'accountNumber' => ['nullable', 'string', 'max:64'],
            'branch' => ['nullable', 'string', 'max:128'],
            'swift' => ['nullable', 'string', 'max:16'],
            'currency' => ['nullable', 'string', 'size:3'],
            'isActive' => ['nullable', 'boolean'],
        ]);

        return response()->json($this->update->execute($id, $data));
    }

    public function openingBalance(string $id, Request $request): JsonResponse
    {
        $data = $request->validate([
            'amountMinor' => ['required', 'integer'],
            'asOfDate' => ['required', 'date'],
        ]);

        return response()->json($this->postOpening->execute($id, $data), 201);
    }
}
