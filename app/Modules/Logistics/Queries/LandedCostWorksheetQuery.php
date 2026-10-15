<?php

declare(strict_types=1);

namespace App\Modules\Logistics\Queries;

use App\Exceptions\NotFoundException;
use App\Models\Consignment;
use App\Models\LandedCost;
use App\Models\Shipment;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;

/**
 * GET /api/v1/shipments/{id}/landed-cost — allocation worksheet.
 *
 * Entered costs are allocated across consignments by the chosen basis
 * (quantity, weight, or volume). Costs tagged to a single consignment
 * stay on that consignment. Recoverable amounts are excluded from the
 * landed total.
 */
final class LandedCostWorksheetQuery
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(string $shipmentId, string $basis = 'quantity'): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'logistics.view');

        $companyId = $this->companyContext->companyId();

        $shipment = Shipment::query()
            ->where('company_id', $companyId)
            ->where('id', $shipmentId)
            ->with('consignments')
            ->first();

        if ($shipment === null) {
            throw new NotFoundException('Shipment not found.');
        }

        if (! in_array($basis, ['quantity', 'weight', 'volume'], true)) {
            $basis = 'quantity';
        }

        $cns = $shipment->consignments;
        $weights = $cns->map(static function (Consignment $c) use ($basis) {
            if ($basis === 'weight') {
                return (float) ($c->weight_kg ?? 0);
            }
            if ($basis === 'volume') {
                return (float) ($c->volume_m3 ?? 0);
            }

            return (float) $c->qty;
        })->all();

        $totalWeight = array_sum($weights);

        $costs = LandedCost::query()
            ->where('company_id', $companyId)
            ->where('shipment_id', $shipmentId)
            ->orderBy('created_at')
            ->get();

        $rows = [];
        $allocated = array_fill(0, $cns->count(), 0);

        foreach ($costs as $cost) {
            $alloc = array_fill(0, $cns->count(), 0);

            if ($cost->recoverable) {
                // recoverable excluded from landed
            } elseif ($cost->consignment_id !== null) {
                foreach ($cns as $i => $cn) {
                    if ($cn->id === $cost->consignment_id) {
                        $alloc[$i] = $cost->amount_minor;
                    }
                }
            } else {
                if ($totalWeight > 0) {
                    $remaining = $cost->amount_minor;
                    $last = $cns->count() - 1;
                    foreach ($cns as $i => $cn) {
                        if ($i === $last) {
                            $alloc[$i] = $remaining;
                        } else {
                            $share = (int) round($cost->amount_minor * ($weights[$i] / $totalWeight));
                            $alloc[$i] = $share;
                            $remaining -= $share;
                        }
                    }
                }
            }

            foreach ($alloc as $i => $v) {
                $allocated[$i] += $v;
            }

            $rows[] = [
                'id' => $cost->id,
                'category' => $cost->category,
                'description' => $cost->description,
                'amountMinor' => $cost->amount_minor,
                'currency' => $cost->currency,
                'recoverable' => $cost->recoverable,
                'status' => $cost->status,
                'consignmentId' => $cost->consignment_id,
                'allocated' => $alloc,
            ];
        }

        $perUnit = [];
        foreach ($cns as $i => $cn) {
            $qty = (float) $cn->qty;
            $perUnit[] = $qty > 0 ? (int) round($allocated[$i] / $qty) : 0;
        }

        $eligibleTotal = $costs->where('recoverable', false)->sum('amount_minor');

        return [
            'shipmentId' => $shipment->id,
            'shipmentNo' => $shipment->shipment_no,
            'basis' => $basis,
            'consignments' => $cns->values()->map(static fn (Consignment $c, int $i) => [
                'id' => $c->id,
                'consignmentNo' => $c->consignment_no,
                'qty' => (string) $c->qty,
                'weightKg' => $c->weight_kg !== null ? (string) $c->weight_kg : null,
                'volumeM3' => $c->volume_m3 !== null ? (string) $c->volume_m3 : null,
                'allocatedMinor' => $allocated[$i],
                'perUnitMinor' => $perUnit[$i],
            ])->all(),
            'costs' => $rows,
            'totalEligibleMinor' => (int) $eligibleTotal,
            'totalAllocatedMinor' => array_sum($allocated),
        ];
    }
}
