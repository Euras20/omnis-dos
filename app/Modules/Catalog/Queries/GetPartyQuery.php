<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Queries;

use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Models\AppUser;
use App\Models\Party;
use App\Modules\Catalog\Actions\CreateParty;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;

/**
 * GET /api/v1/parties/{id}
 */
final class GetPartyQuery
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(string $id): array
    {
        $actorId = $this->companyContext->userId();
        $companyId = $this->companyContext->companyId();

        $party = Party::query()
            ->where('company_id', $companyId)
            ->where('id', $id)
            ->first();

        if ($party === null) {
            throw NotFoundException::resource('Party', $id);
        }

        $this->assertCanView($actorId, $party);

        return CreateParty::toArray($party);
    }

    private function assertCanView(string $actorId, Party $party): void
    {
        $actor = AppUser::query()->find($actorId);
        if ($actor !== null && $actor->user_type === 'portal') {
            if ($actor->party_id !== $party->id) {
                throw new ForbiddenException('Portal users may only access their own party.');
            }

            return;
        }

        $permission = $party->party_type === 'customer' ? 'customers.view' : 'suppliers.view';
        $manage = $party->party_type === 'customer' ? 'customers.manage' : 'suppliers.manage';

        if (! $this->permissionChecker->can($actorId, $permission)
            && ! $this->permissionChecker->can($actorId, $manage)) {
            throw new ForbiddenException("Missing permission to view this party ({$permission}).");
        }
    }
}
