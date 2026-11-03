<?php

declare(strict_types=1);

namespace App\Modules\Fulfillment\Queries;

use App\Exceptions\NotFoundException;
use App\Models\GoodsReturn;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;

final class GetReturnQuery
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
        if (! $this->permissionChecker->can($actorId, 'stock.view')
            && ! $this->permissionChecker->can($actorId, 'delivery.view')) {
            $this->permissionChecker->assertCan($actorId, 'stock.view');
        }

        $return = GoodsReturn::query()
            ->where('company_id', $this->companyContext->companyId())
            ->where('id', $id)
            ->first();
        if ($return === null) {
            throw new NotFoundException('Return not found.');
        }

        return [
            'id' => $return->id,
            'returnNo' => $return->return_no,
            'kind' => $return->kind,
            'status' => $return->status,
            'salesOrderId' => $return->sales_order_id,
            'salesOrderLineId' => $return->sales_order_line_id,
            'deliveryNoteId' => $return->delivery_note_id,
            'deliveryNoteLineId' => $return->delivery_note_line_id,
            'partyId' => $return->party_id,
            'itemId' => $return->item_id,
            'locationId' => $return->location_id,
            'restockLocationId' => $return->restock_location_id,
            'lot' => $return->lot,
            'qty' => (float) $return->qty,
            'unitCostMinor' => $return->unit_cost_minor,
            'reason' => $return->reason,
            'authorization' => $return->authorization,
            'creditNoteId' => $return->credit_note_id,
            'journalId' => $return->journal_id,
            'returnDate' => $return->return_date?->format('Y-m-d'),
            'createdAt' => $return->created_at?->toIso8601String(),
        ];
    }
}
