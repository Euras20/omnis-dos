<?php

declare(strict_types=1);

namespace App\Modules\Fulfillment\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\NotFoundException;
use App\Models\Account;
use App\Models\GoodsReturn;
use App\Models\Item;
use App\Models\Location;
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
 * POST /api/v1/returns/{id}/inspect — QC restock or write-off.
 * Sales demand is not reopened.
 */
final class InspectGoodsReturn
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
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function execute(string $id, array $data): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'quality.manage');

        $decision = strtolower(trim((string) ($data['decision'] ?? '')));
        if (! in_array($decision, ['restock', 'scrap'], true)) {
            throw new BusinessRuleException('decision must be restock or scrap.');
        }

        $companyId = $this->companyContext->companyId();

        return DB::transaction(function () use ($companyId, $actorId, $id, $decision, $data) {
            $return = GoodsReturn::query()
                ->where('company_id', $companyId)
                ->where('id', $id)
                ->lockForUpdate()
                ->first();
            if ($return === null) {
                throw new NotFoundException('Return not found.');
            }
            if ($return->status !== 'awaiting_inspection') {
                throw new BusinessRuleException(
                    "Only a return awaiting inspection can be inspected (current: {$return->status})."
                );
            }

            $qty = (float) $return->qty;
            $unitCost = (int) $return->unit_cost_minor;
            $costTotal = (int) round($qty * $unitCost);
            $date = now()->toDateString();

            if ($decision === 'restock') {
                $destination = $this->restockLocation($companyId, $return, $data['locationId'] ?? null);
                $this->movementService->record(new MovementRequest(
                    companyId: $companyId,
                    movementType: MovementType::TRANSFER_OUT,
                    itemId: (string) $return->item_id,
                    locationId: (string) $return->location_id,
                    lot: $return->lot,
                    qty: number_format(-$qty, 3, '.', ''),
                    unitCostMinor: $unitCost,
                    movementDate: $date,
                    sourceType: 'goods_return',
                    sourceId: $return->id,
                    salesOrderLineId: $return->sales_order_line_id,
                    goodsReceiptLineId: null,
                    stockAssignmentId: null,
                    journalId: null,
                    memo: "Return restock out {$return->return_no}",
                    actorId: (string) $actorId,
                    requestId: $this->companyContext->requestId(),
                ));
                $this->movementService->record(new MovementRequest(
                    companyId: $companyId,
                    movementType: MovementType::TRANSFER_IN,
                    itemId: (string) $return->item_id,
                    locationId: (string) $destination->id,
                    lot: $return->lot,
                    qty: number_format($qty, 3, '.', ''),
                    unitCostMinor: $unitCost,
                    movementDate: $date,
                    sourceType: 'goods_return',
                    sourceId: $return->id,
                    salesOrderLineId: $return->sales_order_line_id,
                    goodsReceiptLineId: null,
                    stockAssignmentId: null,
                    journalId: null,
                    memo: "Return restock in {$return->return_no}",
                    actorId: (string) $actorId,
                    requestId: $this->companyContext->requestId(),
                ));
                $return->status = 'restocked';
                $return->restock_location_id = $destination->id;
            } else {
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
                    salesOrderLineId: $return->sales_order_line_id,
                    goodsReceiptLineId: null,
                    stockAssignmentId: null,
                    journalId: null,
                    memo: "Write-off {$return->return_no}",
                    actorId: (string) $actorId,
                    requestId: $this->companyContext->requestId(),
                ));
                if ($costTotal > 0) {
                    $inventory = $this->accountByCode($companyId, '1300');
                    $cogs = $this->accountByCode($companyId, '5000');
                    $this->postingService->post(new PostingRequest(
                        companyId: $companyId,
                        postingDate: $date,
                        postingKey: PostingKey::make('goods_return', $return->id, 'write_off'),
                        sourceType: 'goods_return',
                        sourceId: $return->id,
                        eventType: 'write_off',
                        description: "Write-off damaged return {$return->return_no}",
                        lines: [
                            new PostingLine(accountId: $cogs->id, debitMinor: $costTotal, creditMinor: 0, memo: $return->return_no),
                            new PostingLine(accountId: $inventory->id, debitMinor: 0, creditMinor: $costTotal, memo: $return->return_no),
                        ],
                        actorId: (string) $actorId,
                        requestId: $this->companyContext->requestId(),
                    ));
                }
                $return->status = 'written_off';
            }

            $return->inspected_by = $actorId;
            $return->save();

            $this->auditWriter->write(
                action: 'goods_return.inspected',
                entityType: 'goods_return',
                entityId: $return->id,
                detail: ['status' => $return->status, 'decision' => $decision],
                actorId: $actorId,
            );

            return [
                'id' => $return->id,
                'returnNo' => $return->return_no,
                'status' => $return->status,
            ];
        });
    }

    private function restockLocation(string $companyId, GoodsReturn $return, mixed $requested): Location
    {
        if (is_string($requested) && $requested !== '') {
            $location = Location::query()
                ->where('company_id', $companyId)
                ->where('id', $requested)
                ->where('is_active', true)
                ->first();
            if ($location === null) {
                throw new NotFoundException('Restock location not found.');
            }

            return $location;
        }

        $item = Item::query()->where('company_id', $companyId)->where('id', $return->item_id)->first();
        if ($item !== null && $item->default_location_id !== null) {
            $location = Location::query()->where('id', $item->default_location_id)->first();
            if ($location !== null) {
                return $location;
            }
        }

        $location = Location::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->where('loc_type', 'store')
            ->first();
        if ($location === null) {
            throw new BusinessRuleException('No restock location. Pass locationId.');
        }

        return $location;
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
