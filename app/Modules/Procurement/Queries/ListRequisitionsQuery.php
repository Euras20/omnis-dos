<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Queries;

use App\Models\Requisition;
use App\Modules\Procurement\Actions\CreateRequisition;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;

final class ListRequisitionsQuery
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
    ) {
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{data: list<array<string, mixed>>, total: int}
     */
    public function execute(array $filters = []): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'po.view');
        $companyId = $this->companyContext->companyId();
        $limit = min(max((int) ($filters['limit'] ?? 50), 1), 200);
        $offset = max((int) ($filters['offset'] ?? 0), 0);

        $q = Requisition::query()
            ->with('lines')
            ->where('company_id', $companyId)
            ->orderByDesc('request_date')
            ->orderByDesc('created_at');

        if (! empty($filters['status'])) {
            $q->where('status', (string) $filters['status']);
        }

        $total = (clone $q)->count();
        $rows = $q->limit($limit)->offset($offset)->get();

        return [
            'data' => $rows->map(static fn (Requisition $r) => CreateRequisition::toArray($r))->all(),
            'total' => $total,
        ];
    }
}
