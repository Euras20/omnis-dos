<?php

declare(strict_types=1);

namespace App\Modules\Quality\Actions;

use App\Exceptions\BusinessRuleException;
use App\Models\Item;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;

/**
 * Reject from quarantine with a form (why, when, action).
 *
 * Creates a nonconformance. Stock movement to the rejected location is left to
 * the existing inventory movement path (caller may also move stock); this
 * command owns the quality document.
 *
 * POST /api/v1/quality/quarantine/reject
 */
final class RejectQuarantine
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly CreateNonconformance $createNonconformance,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function execute(array $data): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'quality.manage');

        $why = trim((string) ($data['why'] ?? $data['issue'] ?? ''));
        $when = isset($data['when']) ? (string) $data['when'] : (isset($data['occurredAt']) ? (string) $data['occurredAt'] : null);
        $action = trim((string) ($data['action'] ?? ''));
        $sku = isset($data['sku']) ? trim((string) $data['sku']) : null;
        $itemId = isset($data['itemId']) ? (string) $data['itemId'] : null;
        $qty = (string) ($data['qty'] ?? '0');
        $lot = isset($data['lot']) ? trim((string) $data['lot']) : null;
        $locationId = isset($data['locationId']) ? (string) $data['locationId'] : null;

        if ($why === '') {
            throw new BusinessRuleException('Why (issue) is required.');
        }
        if ($when === null || $when === '') {
            throw new BusinessRuleException('When is required.');
        }
        if ($action === '') {
            throw new BusinessRuleException('Action is required.');
        }
        if ((float) $qty <= 0) {
            throw new BusinessRuleException('Quantity must be greater than zero.');
        }
        if (($sku === null || $sku === '') && $itemId === null) {
            throw new BusinessRuleException('sku or itemId is required.');
        }

        $companyId = $this->companyContext->companyId();
        if ($itemId === null && $sku !== null) {
            $item = Item::query()
                ->where('company_id', $companyId)
                ->where('sku', $sku)
                ->first();
            $itemId = $item?->id;
        }

        return $this->createNonconformance->execute([
            'issue' => $why,
            'occurredAt' => $when,
            'action' => $action,
            'cause' => isset($data['cause']) && trim((string) $data['cause']) !== ''
                ? trim((string) $data['cause'])
                : ($lot !== null && $lot !== '' ? "Rejected from quarantine (lot {$lot})" : 'Rejected from quarantine'),
            'sourceType' => 'quarantine',
            'sourceId' => $locationId,
            'itemId' => $itemId,
            'sku' => $sku,
            'qty' => $qty,
            'dueDate' => $data['dueDate'] ?? null,
            'ownerId' => $actorId,
        ]);
    }
}
