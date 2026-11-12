<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Queries;

use App\Exceptions\NotFoundException;
use App\Models\Rfq;
use App\Modules\Procurement\Actions\CreateRfq;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;

final class ListRfqsQuery
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

        $q = Rfq::query()
            ->with(['lines', 'bids.supplier', 'awardedPurchaseOrder'])
            ->where('company_id', $companyId)
            ->orderByDesc('issue_date')
            ->orderByDesc('created_at');

        if (! empty($filters['status'])) {
            $q->where('status', (string) $filters['status']);
        }

        $total = (clone $q)->count();
        $rows = $q->limit($limit)->offset($offset)->get();

        return [
            'data' => $rows->map(static fn (Rfq $r) => CreateRfq::toArray($r))->all(),
            'total' => $total,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $id): array
    {
        $this->permissionChecker->assertCan($this->companyContext->userId(), 'po.view');

        $rfq = Rfq::query()
            ->with(['lines', 'bids.supplier', 'awardedPurchaseOrder'])
            ->where('company_id', $this->companyContext->companyId())
            ->where('id', $id)
            ->first();
        if ($rfq === null) {
            throw new NotFoundException('RFQ not found.');
        }

        return CreateRfq::toArray($rfq);
    }
}
