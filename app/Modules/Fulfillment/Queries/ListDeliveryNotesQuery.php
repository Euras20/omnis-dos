<?php

declare(strict_types=1);

namespace App\Modules\Fulfillment\Queries;

use App\Models\DeliveryNote;
use App\Models\DeliveryNoteLine;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;

/**
 * GET /api/v1/delivery-notes — board list (optionally filtered by status or order).
 */
final class ListDeliveryNotesQuery
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(?string $status = null, ?string $salesOrderId = null, int $limit = 50, int $offset = 0): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'stock.view');

        $companyId = $this->companyContext->companyId();

        $query = DeliveryNote::query()
            ->where('company_id', $companyId)
            ->with('lines')
            ->orderByDesc('planned_date')
            ->orderByDesc('created_at');

        if ($status !== null && $status !== '') {
            $query->where('status', $status);
        }
        if ($salesOrderId !== null && $salesOrderId !== '') {
            $query->where('sales_order_id', $salesOrderId);
        }

        $total = (clone $query)->count();
        $rows = $query->limit($limit)->offset($offset)->get();

        return [
            'data' => $rows->map(fn (DeliveryNote $n) => $this->toArray($n))->all(),
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(DeliveryNote $n): array
    {
        return [
            'id' => $n->id,
            'deliveryNo' => $n->delivery_no,
            'salesOrderId' => $n->sales_order_id,
            'status' => $n->status,
            'plannedDate' => $n->planned_date?->format('Y-m-d'),
            'revisedDate' => $n->revised_date?->format('Y-m-d'),
            'route' => $n->route,
            'packages' => $n->packages,
            'weightKg' => $n->weight_kg !== null ? (string) $n->weight_kg : null,
            'vehicle' => $n->vehicle,
            'driver' => $n->driver,
            'attempt' => $n->attempt,
            'receiver' => $n->receiver,
            'podReference' => $n->pod_reference,
            'lineCount' => $n->lines->count(),
            'rowVersion' => $n->row_version,
            'createdAt' => $n->created_at?->toIso8601String(),
        ];
    }
}
