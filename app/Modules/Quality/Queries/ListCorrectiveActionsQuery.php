<?php

declare(strict_types=1);

namespace App\Modules\Quality\Queries;

use App\Models\CorrectiveAction;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;

/**
 * GET /api/v1/quality/corrective-actions
 */
final class ListCorrectiveActionsQuery
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
        $query = CorrectiveAction::query()
            ->with('nonconformance')
            ->where('company_id', $companyId);

        if ($status !== null && $status !== '') {
            $query->where('status', $status);
        }

        $total = (clone $query)->count();
        $rows = $query
            ->orderByDesc('created_at')
            ->limit($limit)
            ->offset($offset)
            ->get()
            ->map(fn (CorrectiveAction $c) => [
                'id' => $c->id,
                'capaNo' => $c->capa_no,
                'nonconformanceId' => $c->nonconformance_id,
                'ncrNo' => $c->nonconformance?->ncr_no,
                'action' => $c->action,
                'comments' => $c->comments,
                'ownerId' => $c->owner_id,
                'verifierId' => $c->verifier_id,
                'status' => $c->status,
                'dueDate' => $c->due_date?->format('Y-m-d'),
                'createdAt' => $c->created_at?->toIso8601String(),
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
