<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\NotFoundException;
use App\Models\Item;
use App\Modules\Inventory\Domain\MovementRequest;
use App\Modules\Inventory\Domain\MovementType;
use App\Modules\Inventory\Services\MovementService;
use App\Modules\Ledger\Domain\PostingKey;
use App\Modules\Ledger\Domain\PostingLine;
use App\Modules\Ledger\Domain\PostingRequest;
use App\Modules\Ledger\Services\PostingService;
use App\Modules\Ledger\Services\RoleResolver;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use App\Shared\Support\Uuid;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/items/{id}/opening-balance — opening stock for an item.
 *
 * Creates a stock movement and Dr inventory / Cr 3900.
 */
final class PostItemOpeningBalance
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly MovementService $movementService,
        private readonly PostingService $postingService,
        private readonly RoleResolver $roleResolver,
        private readonly AuditWriter $auditWriter,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function execute(string $itemId, array $data): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'stock.move');

        $qty = (float) ($data['qty'] ?? 0);
        if ($qty <= 0) {
            throw new BusinessRuleException('qty must be greater than 0.');
        }

        $unitCost = (int) ($data['unitCostMinor'] ?? 0);
        if ($unitCost <= 0) {
            throw new BusinessRuleException('unitCostMinor must be greater than 0.');
        }

        $locationId = (string) ($data['locationId'] ?? '');
        $asOf = (string) ($data['asOfDate'] ?? '');
        if ($locationId === '' || $asOf === '') {
            throw new BusinessRuleException('locationId and asOfDate are required.');
        }

        $companyId = $this->companyContext->companyId();
        $amount = (int) round($qty * $unitCost);

        return DB::transaction(function () use ($itemId, $qty, $unitCost, $amount, $locationId, $asOf, $companyId, $actorId) {
            $item = Item::query()
                ->where('company_id', $companyId)
                ->where('id', $itemId)
                ->lockForUpdate()
                ->first();

            if ($item === null) {
                throw new NotFoundException('Item not found.');
            }

            if ($item->opening_balance_doc_id !== null) {
                throw new BusinessRuleException('Opening stock already recorded. Correct via stock adjustment.');
            }

            $invId = $item->inv_account_id ?? $this->roleResolver->id($companyId, 'INVENTORY');
            $clearingId = $this->clearingId($companyId);

            $docId = Uuid::v7();

            $journalId = $this->postingService->post(new PostingRequest(
                companyId: $companyId,
                postingDate: $asOf,
                postingKey: PostingKey::make('opening_balance', $docId, 'item'),
                sourceType: 'opening_balance',
                sourceId: $docId,
                eventType: 'item_opening',
                description: "Opening stock {$item->sku}",
                lines: [
                    new PostingLine(accountId: $invId, debitMinor: $amount, creditMinor: 0),
                    new PostingLine(accountId: $clearingId, debitMinor: 0, creditMinor: $amount),
                ],
                actorId: $actorId,
            ));

            $this->movementService->record(new MovementRequest(
                companyId: $companyId,
                movementType: MovementType::OPENING,
                itemId: $item->id,
                locationId: $locationId,
                lot: 'OB-'.$item->sku,
                qty: number_format($qty, 3, '.', ''),
                unitCostMinor: $unitCost,
                movementDate: $asOf,
                sourceType: 'opening_balance',
                sourceId: $docId,
                journalId: $journalId,
                memo: 'Opening stock',
                actorId: $actorId,
            ));

            $item->opening_balance_doc_id = $docId;
            $item->opening_balance_date = $asOf;
            $item->opening_balance_qty = $qty;
            $item->opening_balance_minor = $amount;
            $item->save();

            $this->auditWriter->write(
                action: 'item.opening_balance',
                entityType: 'item',
                entityId: $item->id,
                detail: ['qty' => $qty, 'amountMinor' => $amount, 'asOf' => $asOf],
                actorId: $actorId,
            );

            return [
                'itemId' => $item->id,
                'docId' => $docId,
                'qty' => $qty,
                'unitCostMinor' => $unitCost,
                'amountMinor' => $amount,
                'locationId' => $locationId,
                'asOfDate' => $asOf,
                'journalId' => $journalId,
            ];
        });
    }

    private function clearingId(string $companyId): string
    {
        return $this->roleResolver->id($companyId, 'OPENING_BALANCE_EQUITY');
    }
}
