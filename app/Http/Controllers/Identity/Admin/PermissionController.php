<?php

declare(strict_types=1);

namespace App\Http\Controllers\Identity\Admin;

use App\Modules\Identity\Queries\ListPermissionsQuery;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use Illuminate\Http\JsonResponse;

final class PermissionController
{
    public function __construct(
        private readonly ListPermissionsQuery $listPermissionsQuery,
        private readonly PermissionChecker $permissionChecker,
        private readonly CompanyContext $companyContext,
    ) {
    }

    public function index(): JsonResponse
    {
        $this->permissionChecker->assertCan($this->companyContext->userId(), 'admin.users');

        return response()->json($this->listPermissionsQuery->execute());
    }
}
