<?php

declare(strict_types=1);

namespace App\Modules\Fulfillment\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\NotFoundException;
use App\Models\Account;
use App\Models\GoodsReturn;
use App\Modules\Inventory\Domain\MovementRequest;
use App\Modules\Inventory\Domain\MovementType;
use App\Modules\Inventory\Services\MovementService;
use App\Modules\Ledger\Domain\PostingKey;
use App\Modules\Ledger\Domain\PostingLine;
use App\Modules\Ledger\Domain\PostingRequest;
use App\Modules\Ledger\Services\PostingService;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/returns/{id}/dispatch — send a supplier return out.
 * Reduces stock and GRNI. Does not touch customer invoices.
 */
final class DispatchSupplierReturn
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly MovementService $movementService,
        private readonly PostingService $postingService,
        private readonly AuditWriter $auditWriter,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(string $id): array
    {
        $actorId = $this->companyContext->userId();
        if (! $this->permissionChecker->can($actorId, 'delivery.return')
            && ! $this->permissionChecker->can($actorId, 'stock.move')) {
            $this->permissionChecker->assertCan($actorId, 'delivery.return');
        }

        $companyId = $this->companyContext->companyId();

        return DB::transaction(function () use ($companyId, $actorId, $id) {
            $return = GoodsReturn::query()
                ->where('company_id', $companyId)
                ->where('id', $id)
                ->lockForUpdate()
                ->first();
            if ($return === null) {
                throw new NotFoundException('Return not found.');
            }
            if ($return->kind !== 'supplier') {
                throw new BusinessRuleException('Only a supplier return can be dispatched.');
            }
            if ($return->status !== 'awaiting_dispatch') {
                throw new BusinessRuleException(
                    "Only a supplier return awaiting dispatch can be dispatched (current: {$return->status})."
                );
            }

            $qty = (float) $return->qty;
            $unitCost = (int) $return->unit_cost_minor;
            $costTotal = (int) round($qty * $unitCost);
            $date = now()->toDateString();

            $this->movementService->record(new MovementRequest(
                companyId: $companyId,
                movementType: MovementType::ISSUE,
                itemId: (string) $return->item_id,
                locationId: (string) $return->location_id,
                lot: $return->lot,
                qty: number_format(-$qty, 3, '.', ''),
                unitCostMinor: $unitCost,
                movementDate: $date,
                sourceType: 'goods_return',
                sourceId: $return->id,
                salesOrderLineId: null,
                goodsReceiptLineId: null,
                stockAssignmentId: null,
                journalId: null,
                memo: "Supplier return {$return->return_no}",
                actorId: (string) $actorId,
                requestId: $this->companyContext->requestId(),
            ));

            $journalId = null;
            if ($costTotal > 0) {
                $grni = $this->accountByCode($companyId, '2050');
                $inventory = $this->accountByCode($companyId, '1300');
                $journalId = $this->postingService->post(new PostingRequest(
                    companyId: $companyId,
                    postingDate: $date,
                    postingKey: PostingKey::make('goods_return', $return->id, 'dispatch'),
                    sourceType: 'goods_return',
                    sourceId: $return->id,
                    eventType: 'dispatch',
                    description: "Supplier return {$return->return_no}: reduce GRNI",
                    lines: [
                        new PostingLine(accountId: $grni->id, debitMinor: $costTotal, creditMinor: 0, memo: $return->return_no),
                        new PostingLine(accountId: $inventory->id, debitMinor: 0, creditMinor: $costTotal, memo: $return->return_no),
                    ],
                    actorId: (string) $actorId,
                    requestId: $this->companyContext->requestId(),
                ));
                $return->journal_id = $journalId;
            }
            $return->status = 'dispatched';
            $return->save();

            $this->auditWriter->write(
                action: 'goods_return.dispatched',
                entityType: 'goods_return',
                entityId: $return->id,
                detail: ['returnNo' => $return->return_no],
                actorId: $actorId,
            );

            return [
                'id' => $return->id,
                'returnNo' => $return->return_no,
                'status' => $return->status,
                'journalId' => $journalId,
            ];
        });
    }

    private function accountByCode(string $companyId, string $code): Account
    {
        $account = Account::query()
            ->where('company_id', $companyId)
            ->where('code', $code)
            ->where('is_active', true)
            ->first();
        if ($account === null) {
            throw new BusinessRuleException("Required account code {$code} is missing or inactive.");
        }

        return $account;
    }
}
