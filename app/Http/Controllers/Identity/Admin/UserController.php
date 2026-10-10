<?php

declare(strict_types=1);

namespace App\Http\Controllers\Identity\Admin;

use App\Http\Requests\Identity\ReplaceOverridesRequest;
use App\Http\Requests\Identity\StoreUserRequest;
use App\Http\Requests\Identity\UpdateUserRequest;
use App\Modules\Identity\Actions\CreateUser;
use App\Modules\Identity\Actions\ReplaceUserOverrides;
use App\Modules\Identity\Actions\UpdateUser;
use App\Modules\Identity\Queries\GetEffectivePermissionsQuery;
use App\Modules\Identity\Queries\ListUsersQuery;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class UserController
{
    public function __construct(
        private readonly ListUsersQuery $listUsersQuery,
        private readonly CreateUser $createUser,
        private readonly UpdateUser $updateUser,
        private readonly ReplaceUserOverrides $replaceUserOverrides,
        private readonly GetEffectivePermissionsQuery $effectivePermissionsQuery,
        private readonly PermissionChecker $permissionChecker,
        private readonly CompanyContext $companyContext,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->permissionChecker->assertCan($this->companyContext->userId(), 'admin.users');

        $result = $this->listUsersQuery->execute([
            'q' => $request->query('q'),
            'status' => $request->query('status'),
            'limit' => $request->query('limit'),
            'offset' => $request->query('offset'),
        ]);

        return response()->json($result);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = $this->createUser->execute($request->validated());

        return response()->json($user, 201);
    }

    public function update(string $id, UpdateUserRequest $request): JsonResponse
    {
        $user = $this->updateUser->execute($id, $request->validated());

        return response()->json($user);
    }

    public function replaceOverrides(string $id, ReplaceOverridesRequest $request): JsonResponse
    {
        $result = $this->replaceUserOverrides->execute($id, $request->validated());

        return response()->json($result);
    }

    public function effective(string $id): JsonResponse
    {
        $this->permissionChecker->assertCan($this->companyContext->userId(), 'admin.users');

        return response()->json($this->effectivePermissionsQuery->execute($id));
    }
}
