<?php

declare(strict_types=1);

namespace App\Modules\Logistics\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\NotFoundException;
use App\Models\Consignment;
use App\Models\LandedCost;
use App\Models\Shipment;
use App\Modules\Logistics\Domain\LandedCostCategory;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Outbox\OutboxWriter;
use App\Shared\Permissions\PermissionChecker;
use App\Shared\Support\Uuid;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/landed-costs — enter a landed cost against a shipment
 * (whole shipment or a single consignment).
 *
 * Costs are entered, not calculated. Allocation across consignments is
 * computed on read by the worksheet query.
 */
final class EnterLandedCost
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly AuditWriter $auditWriter,
        private readonly OutboxWriter $outboxWriter,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function execute(array $data): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'logistics.costs');

        $companyId = $this->companyContext->companyId();

        $category = (string) ($data['category'] ?? '');
        if (! in_array($category, LandedCostCategory::all(), true)) {
            throw new BusinessRuleException('Unknown landed cost category.');
        }

        $amount = (int) ($data['amountMinor'] ?? 0);
        if ($amount <= 0) {
            throw new BusinessRuleException('amountMinor must be greater than zero.');
        }

        return DB::transaction(function () use ($data, $category, $amount, $companyId, $actorId) {
            $shipmentId = (string) ($data['shipmentId'] ?? '');
            $shipment = Shipment::query()
                ->where('company_id', $companyId)
                ->where('id', $shipmentId)
                ->first();

            if ($shipment === null) {
                throw new NotFoundException('Shipment not found.');
            }

            $consignmentId = $data['consignmentId'] ?? null;
            if ($consignmentId !== null && $consignmentId !== '') {
                $exists = Consignment::query()
                    ->where('company_id', $companyId)
                    ->where('id', $consignmentId)
                    ->where('shipment_id', $shipmentId)
                    ->exists();

                if (! $exists) {
                    throw new NotFoundException('Consignment not found on this shipment.');
                }
            } else {
                $consignmentId = null;
            }

            $cost = new LandedCost;
            $cost->id = Uuid::v7();
            $cost->company_id = $companyId;
            $cost->shipment_id = $shipmentId;
            $cost->consignment_id = $consignmentId;
            $cost->category = $category;
            $cost->description = isset($data['description']) ? trim((string) $data['description']) : null;
            $cost->amount_minor = $amount;
            $cost->currency = strtoupper((string) ($data['currency'] ?? 'GHS'));
            $cost->recoverable = (bool) ($data['recoverable'] ?? false);
            $cost->status = (string) ($data['status'] ?? 'Committed');
            $cost->source = isset($data['source']) ? trim((string) $data['source']) : null;
            $cost->created_by = $actorId;
            $cost->save();

            $this->auditWriter->write(
                'landed_cost.entered',
                'shipment',
                $shipmentId,
                [
                    'category' => $category,
                    'amountMinor' => $amount,
                    'consignmentId' => $consignmentId,
                ],
            );

            $this->outboxWriter->record([
                'aggregate_type' => 'shipment',
                'aggregate_id' => $shipmentId,
                'event_type' => 'landed_cost.entered',
                'payload' => ['category' => $category, 'amountMinor' => $amount],
            ]);

            return [
                'id' => $cost->id,
                'shipmentId' => $cost->shipment_id,
                'consignmentId' => $cost->consignment_id,
                'category' => $cost->category,
                'description' => $cost->description,
                'amountMinor' => $cost->amount_minor,
                'currency' => $cost->currency,
                'recoverable' => $cost->recoverable,
                'status' => $cost->status,
                'createdAt' => $cost->created_at?->toIso8601String(),
            ];
        });
    }
}
