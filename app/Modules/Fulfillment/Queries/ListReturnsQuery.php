<?php

declare(strict_types=1);

namespace App\Modules\Fulfillment\Queries;

use App\Models\GoodsReturn;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;

final class ListReturnsQuery
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
    ) {
    }

    /**
     * @return array{data: list<array<string, mixed>>, limit: int, offset: int}
     */
    public function execute(?string $status = null, ?string $kind = null, int $limit = 50, int $offset = 0): array
    {
        $actorId = $this->companyContext->userId();
        if (! $this->permissionChecker->can($actorId, 'stock.view')
            && ! $this->permissionChecker->can($actorId, 'delivery.view')) {
            $this->permissionChecker->assertCan($actorId, 'stock.view');
        }

        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);

        $query = GoodsReturn::query()
            ->where('company_id', $this->companyContext->companyId())
            ->orderByDesc('created_at');

        if ($status !== null && $status !== '') {
            $query->where('status', $status);
        }
        if ($kind !== null && $kind !== '') {
            $query->where('kind', $kind);
        }

        $rows = $query->offset($offset)->limit($limit)->get();

        return [
            'data' => $rows->map(static fn (GoodsReturn $r) => [
                'id' => $r->id,
                'returnNo' => $r->return_no,
                'kind' => $r->kind,
                'status' => $r->status,
                'salesOrderId' => $r->sales_order_id,
                'deliveryNoteId' => $r->delivery_note_id,
                'partyId' => $r->party_id,
                'itemId' => $r->item_id,
                'qty' => (float) $r->qty,
                'reason' => $r->reason,
                'returnDate' => $r->return_date?->format('Y-m-d'),
            ])->all(),
            'limit' => $limit,
            'offset' => $offset,
        ];
    }
}
