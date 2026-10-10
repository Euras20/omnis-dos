<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ledger;

use App\Http\Requests\Ledger\StoreAccountRequest;
use App\Http\Requests\Ledger\UpdateAccountRequest;
use App\Modules\Ledger\Actions\CreateAccount;
use App\Modules\Ledger\Actions\UpdateAccount;
use App\Modules\Ledger\Queries\ListAccountsQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AccountController
{
    public function __construct(
        private readonly ListAccountsQuery $listAccountsQuery,
        private readonly CreateAccount $createAccount,
        private readonly UpdateAccount $updateAccount,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $result = $this->listAccountsQuery->execute([
            'q' => $request->query('q'),
            'category' => $request->query('category'),
            'status' => $request->query('status'),
            'asOf' => $request->query('asOf'),
            'limit' => $request->query('limit'),
            'offset' => $request->query('offset'),
        ]);

        return response()->json($result);
    }

    public function store(StoreAccountRequest $request): JsonResponse
    {
        $account = $this->createAccount->execute($request->validated());

        return response()->json($account, 201);
    }

    public function update(string $id, UpdateAccountRequest $request): JsonResponse
    {
        $account = $this->updateAccount->execute($id, $request->validated());

        return response()->json($account);
    }
}
