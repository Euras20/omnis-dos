<?php

declare(strict_types=1);

namespace App\Http\Controllers\Identity\Admin;

use App\Modules\Identity\Queries\AccessReviewQuery;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use Illuminate\Http\JsonResponse;

final class AccessReviewController
{
    public function __construct(
        private readonly AccessReviewQuery $accessReviewQuery,
        private readonly PermissionChecker $permissionChecker,
        private readonly CompanyContext $companyContext,
    ) {
    }

    public function __invoke(): JsonResponse
    {
        $this->permissionChecker->assertCan($this->companyContext->userId(), 'admin.users');

        return response()->json($this->accessReviewQuery->execute());
    }
}
