<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Queries;

use App\Models\Location;
use App\Modules\Catalog\Actions\CreateLocation;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;

/**
 * GET /api/v1/locations
 */
final class ListLocationsQuery
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
    ) {
    }

    /**
     * @param  array{status?: ?string, type?: ?string}  $filters
     * @return array{items: list<array<string, mixed>>}
     */
    public function execute(array $filters = []): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'items.view');

        $query = Location::query()->orderBy('code');

        if (($filters['status'] ?? null) === 'active') {
            $query->where('is_active', true);
        } elseif (($filters['status'] ?? null) === 'inactive') {
            $query->where('is_active', false);
        }

        if (! empty($filters['type'])) {
            $query->where('loc_type', $filters['type']);
        }

        $items = $query->get()->map(fn (Location $l) => CreateLocation::toArray($l))->all();

        return ['items' => $items];
    }
}
