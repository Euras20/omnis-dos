<?php

declare(strict_types=1);

namespace App\Http\Controllers\Identity\Admin;

use App\Http\Requests\Identity\StoreRoleRequest;
use App\Http\Requests\Identity\UpdateRoleRequest;
use App\Modules\Identity\Actions\CreateRole;
use App\Modules\Identity\Actions\DeleteRole;
use App\Modules\Identity\Actions\UpdateRole;
use App\Modules\Identity\Queries\ListRolesQuery;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use Illuminate\Http\JsonResponse;

final class RoleController
{
    public function __construct(
        private readonly ListRolesQuery $listRolesQuery,
        private readonly CreateRole $createRole,
        private readonly UpdateRole $updateRole,
        private readonly DeleteRole $deleteRole,
        private readonly PermissionChecker $permissionChecker,
        private readonly CompanyContext $companyContext,
    ) {
    }

    public function index(): JsonResponse
    {
        $this->permissionChecker->assertCan($this->companyContext->userId(), 'admin.users');

        return response()->json($this->listRolesQuery->execute());
    }

    public function store(StoreRoleRequest $request): JsonResponse
    {
        $role = $this->createRole->execute($request->validated());

        return response()->json($role, 201);
    }

    public function update(string $code, UpdateRoleRequest $request): JsonResponse
    {
        $role = $this->updateRole->execute($code, $request->validated());

        return response()->json($role);
    }

    public function destroy(string $code): JsonResponse
    {
        $this->deleteRole->execute($code);

        return response()->json(null, 204);
    }
}
