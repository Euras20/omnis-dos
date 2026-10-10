<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\NotFoundException;
use App\Models\Item;
use App\Models\Location;
use App\Models\StockAssignment;
use App\Modules\Inventory\Domain\AssignmentStatus;
use App\Modules\Inventory\Services\MovementService;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use App\Shared\Support\Uuid;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/stock/assignments — reserve stock for a sales order line (or free).
 * Creates a pending assignment; stock keeper confirms via DecideAssignment.
 */
final class CreateAssignment
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly MovementService $movementService,
        private readonly AuditWriter $auditWriter,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function execute(array $data): array
    {
        $actorId = $this->companyContext->userId();
        // Creating an assignment is a stock move intent; require stock.move or sales role.
        // Catalogue has stock.move for movements; assignment create is also stock-related.
        $this->permissionChecker->assertCan($actorId, 'stock.move');

        $companyId = $this->companyContext->companyId();
        $itemId = (string) ($data['itemId'] ?? '');
        $locationId = (string) ($data['locationId'] ?? '');
        $lot = isset($data['lot']) ? trim((string) $data['lot']) : null;
        if ($lot === '') {
            $lot = null;
        }
        $qty = $data['qty'] ?? null;
        $salesOrderLineId = isset($data['salesOrderLineId']) ? (string) $data['salesOrderLineId'] : null;
        $memo = isset($data['memo']) ? trim((string) $data['memo']) : null;
        $requestId = $this->companyContext->requestId();

        if ($itemId === '') {
            throw new BusinessRuleException('itemId is required.');
        }
        if ($locationId === '') {
            throw new BusinessRuleException('locationId is required.');
        }
        if ($qty === null || ! is_numeric($qty) || (float) $qty <= 0) {
            throw new BusinessRuleException('qty must be a positive number.');
        }

        $item = Item::query()
            ->where('company_id', $companyId)
            ->where('id', $itemId)
            ->where('is_active', true)
            ->first();
        if ($item === null) {
            throw new NotFoundException('Item not found or inactive.');
        }

        $location = Location::query()
            ->where('company_id', $companyId)
            ->where('id', $locationId)
            ->where('is_active', true)
            ->first();
        if ($location === null) {
            throw new NotFoundException('Location not found or inactive.');
        }

        $qtyStr = number_format((float) $qty, 3, '.', '');

        return DB::transaction(function () use (
            $companyId,
            $itemId,
            $locationId,
            $lot,
            $qtyStr,
            $salesOrderLineId,
            $memo,
            $actorId,
            $requestId,
        ) {
            // Reserve only if available covers the request (pending counts as assigned).
            $this->movementService->assertSufficientAvailable(
                $companyId,
                $itemId,
                $locationId,
                $lot,
                (float) $qtyStr,
            );

            $id = Uuid::v7();
            $assignment = new StockAssignment;
            $assignment->id = $id;
            $assignment->company_id = $companyId;
            $assignment->item_id = $itemId;
            $assignment->location_id = $locationId;
            $assignment->lot = $lot;
            $assignment->qty = $qtyStr;
            $assignment->status = AssignmentStatus::PENDING;
            $assignment->sales_order_line_id = $salesOrderLineId;
            $assignment->requested_by = $actorId;
            $assignment->memo = $memo;
            $assignment->created_by = $actorId;
            $assignment->request_id = $requestId;
            $assignment->save();

            $this->auditWriter->write(
                action: 'stock.assignment.create',
                entityType: 'stock_assignment',
                entityId: $id,
                detail: [
                    'itemId' => $itemId,
                    'locationId' => $locationId,
                    'lot' => $lot,
                    'qty' => $qtyStr,
                    'salesOrderLineId' => $salesOrderLineId,
                    'status' => AssignmentStatus::PENDING,
                ],
            );

            return $this->toArray($assignment);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(StockAssignment $a): array
    {
        return [
            'id' => $a->id,
            'itemId' => $a->item_id,
            'locationId' => $a->location_id,
            'lot' => $a->lot,
            'qty' => (string) $a->qty,
            'status' => $a->status,
            'salesOrderLineId' => $a->sales_order_line_id,
            'requestedBy' => $a->requested_by,
            'decidedBy' => $a->decided_by,
            'decidedAt' => $a->decided_at?->toIso8601String(),
            'decisionNote' => $a->decision_note,
            'memo' => $a->memo,
            'createdAt' => $a->created_at?->toIso8601String(),
        ];
    }
}
