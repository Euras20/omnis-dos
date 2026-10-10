<?php

declare(strict_types=1);

namespace App\Modules\Identity\Queries;

use App\Exceptions\NotFoundException;
use App\Models\AppUser;
use App\Modules\Identity\Services\EffectivePermissionResolver;
use App\Shared\Company\CompanyContext;

/**
 * GET /api/v1/admin/users/{id}/effective
 */
final class GetEffectivePermissionsQuery
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly EffectivePermissionResolver $resolver,
    ) {
    }

    /**
     * @return array{userId: string, permissions: list<array<string, mixed>>}
     */
    public function execute(string $userId): array
    {
        $user = AppUser::query()
            ->where('company_id', $this->companyContext->companyId())
            ->where('id', $userId)
            ->first();

        if ($user === null) {
            throw new NotFoundException('User not found.');
        }

        return [
            'userId' => (string) $user->id,
            'permissions' => $this->resolver->forUser($user),
        ];
    }
}
