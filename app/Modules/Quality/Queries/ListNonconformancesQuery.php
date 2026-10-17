<?php

declare(strict_types=1);

namespace App\Modules\Quality\Queries;

use App\Models\Nonconformance;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;

/**
 * GET /api/v1/quality/nonconformances
 */
final class ListNonconformancesQuery
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(?string $status, int $limit, int $offset): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'quality.view');

        $companyId = $this->companyContext->companyId();
        $query = Nonconformance::query()->where('company_id', $companyId);

        if ($status !== null && $status !== '') {
            $query->where('status', $status);
        }

        $total = (clone $query)->count();
        $rows = $query
            ->orderByDesc('created_at')
            ->limit($limit)
            ->offset($offset)
            ->get()
            ->map(fn (Nonconformance $n) => [
                'id' => $n->id,
                'ncrNo' => $n->ncr_no,
                'sourceType' => $n->source_type,
                'sourceId' => $n->source_id,
                'itemId' => $n->item_id,
                'sku' => $n->sku,
                'qty' => (string) $n->qty,
                'issue' => $n->issue,
                'cause' => $n->cause,
                'action' => $n->action,
                'ownerId' => $n->owner_id,
                'status' => $n->status,
                'dueDate' => $n->due_date?->format('Y-m-d'),
                'occurredAt' => $n->occurred_at?->format('Y-m-d'),
                'correctiveActionId' => $n->corrective_action_id,
                'createdAt' => $n->created_at?->toIso8601String(),
            ])
            ->all();

        return [
            'data' => $rows,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }
}
