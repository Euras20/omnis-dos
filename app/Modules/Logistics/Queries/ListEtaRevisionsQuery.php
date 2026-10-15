<?php

declare(strict_types=1);

namespace App\Modules\Logistics\Queries;

use App\Models\EtaRevision;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;

/** GET /api/v1/eta-revisions */
final class ListEtaRevisionsQuery
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(int $limit = 50, int $offset = 0): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'logistics.view');

        $companyId = $this->companyContext->companyId();

        $query = EtaRevision::query()
            ->where('company_id', $companyId)
            ->orderByDesc('revised_at');

        $total = (clone $query)->count();
        $rows = $query->limit($limit)->offset($offset)->get();

        return [
            'data' => $rows->map(static fn (EtaRevision $r) => [
                'id' => $r->id,
                'shipmentId' => $r->shipment_id,
                'salesOrderId' => $r->sales_order_id,
                'originalPromise' => $r->original_promise?->format('Y-m-d'),
                'previous' => $r->previous?->format('Y-m-d'),
                'revised' => $r->revised?->format('Y-m-d'),
                'source' => $r->source,
                'confidence' => $r->confidence,
                'reason' => $r->reason,
                'revisedBy' => $r->revised_by,
                'revisedAt' => $r->revised_at?->toIso8601String(),
            ])->all(),
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }
}
