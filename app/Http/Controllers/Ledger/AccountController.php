<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ledger;

use App\Http\Requests\Ledger\BulkUpdateAccountsRequest;
use App\Http\Requests\Ledger\LockAccountRequest;
use App\Http\Requests\Ledger\MapAccountRoleRequest;
use App\Http\Requests\Ledger\StoreAccountRequest;
use App\Http\Requests\Ledger\UpdateAccountRequest;
use App\Modules\Ledger\Actions\BulkUpdateAccounts;
use App\Modules\Ledger\Actions\CreateAccount;
use App\Modules\Ledger\Actions\DeleteAccount;
use App\Modules\Ledger\Actions\LockAccount;
use App\Modules\Ledger\Actions\MapAccountRole;
use App\Modules\Ledger\Actions\UnlockAccount;
use App\Modules\Ledger\Actions\UpdateAccount;
use App\Modules\Ledger\Queries\AccountGroupLookupsQuery;
use App\Modules\Ledger\Queries\AccountTreeQuery;
use App\Modules\Ledger\Queries\ListAccountRolesQuery;
use App\Modules\Ledger\Queries\ListAccountsQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AccountController
{
    public function __construct(
        private readonly ListAccountsQuery $listAccountsQuery,
        private readonly AccountTreeQuery $accountTreeQuery,
        private readonly AccountGroupLookupsQuery $groupLookupsQuery,
        private readonly ListAccountRolesQuery $listAccountRolesQuery,
        private readonly CreateAccount $createAccount,
        private readonly UpdateAccount $updateAccount,
        private readonly DeleteAccount $deleteAccount,
        private readonly MapAccountRole $mapAccountRole,
        private readonly LockAccount $lockAccount,
        private readonly UnlockAccount $unlockAccount,
        private readonly BulkUpdateAccounts $bulkUpdateAccounts,
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

    public function tree(): JsonResponse
    {
        return response()->json($this->accountTreeQuery->execute());
    }

    public function groupLookups(): JsonResponse
    {
        return response()->json($this->groupLookupsQuery->execute());
    }

    public function roles(): JsonResponse
    {
        return response()->json($this->listAccountRolesQuery->execute());
    }

    public function mapRole(string $roleKey, MapAccountRoleRequest $request): JsonResponse
    {
        return response()->json($this->mapAccountRole->execute($roleKey, $request->validated()));
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

    public function destroy(string $id): JsonResponse
    {
        return response()->json($this->deleteAccount->execute($id));
    }

    public function lock(string $id, LockAccountRequest $request): JsonResponse
    {
        return response()->json($this->lockAccount->execute($id, $request->validated()));
    }

    public function unlock(string $id, LockAccountRequest $request): JsonResponse
    {
        return response()->json($this->unlockAccount->execute($id, $request->validated()));
    }

    public function bulk(BulkUpdateAccountsRequest $request): JsonResponse
    {
        return response()->json($this->bulkUpdateAccounts->execute($request->validated()));
    }
}
