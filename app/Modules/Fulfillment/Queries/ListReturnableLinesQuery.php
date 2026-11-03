<?php

declare(strict_types=1);

namespace App\Modules\Fulfillment\Queries;

use App\Models\DeliveryNote;
use App\Models\DeliveryNoteLine;
use App\Models\GoodsReturn;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;

/**
 * Delivered lines that still have quantity that can come back.
 */
final class ListReturnableLinesQuery
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
    ) {
    }

    /**
     * @return array{data: list<array<string, mixed>>}
     */
    public function execute(): array
    {
        $actorId = $this->companyContext->userId();
        if (! $this->permissionChecker->can($actorId, 'stock.view')
            && ! $this->permissionChecker->can($actorId, 'delivery.view')) {
            $this->permissionChecker->assertCan($actorId, 'stock.view');
        }

        $companyId = $this->companyContext->companyId();

        $notes = DeliveryNote::query()
            ->where('company_id', $companyId)
            ->where('status', 'delivered')
            ->get()
            ->keyBy('id');

        $lines = DeliveryNoteLine::query()
            ->where('company_id', $companyId)
            ->whereIn('delivery_note_id', $notes->keys())
            ->get();

        $returned = GoodsReturn::query()
            ->where('company_id', $companyId)
            ->whereNotNull('delivery_note_line_id')
            ->whereNotIn('status', ['cancelled'])
            ->selectRaw('delivery_note_line_id, SUM(qty) as qty')
            ->groupBy('delivery_note_line_id')
            ->pluck('qty', 'delivery_note_line_id');

        $data = [];
        foreach ($lines as $line) {
            $note = $notes->get($line->delivery_note_id);
            if ($note === null) {
                continue;
            }
            $accepted = (float) ($line->accepted_qty ?? $line->qty);
            $already = (float) ($returned[$line->id] ?? 0);
            $remaining = $accepted - $already;
            if ($remaining <= 0.0005) {
                continue;
            }
            $data[] = [
                'deliveryNoteLineId' => $line->id,
                'deliveryNoteId' => $note->id,
                'deliveryNo' => $note->delivery_no,
                'salesOrderId' => $note->sales_order_id,
                'salesOrderLineId' => $line->sales_order_line_id,
                'itemId' => $line->item_id,
                'lot' => $line->lot,
                'acceptedQty' => $accepted,
                'returnedQty' => $already,
                'remainingQty' => $remaining,
            ];
        }

        return ['data' => $data];
    }
}
