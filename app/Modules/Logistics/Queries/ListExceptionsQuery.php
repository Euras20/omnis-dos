<?php

declare(strict_types=1);

namespace App\Modules\Logistics\Queries;

use App\Models\Consignment;
use App\Models\CustomsDeclaration;
use App\Models\Shipment;
use App\Modules\Logistics\Domain\ConsignmentStatus;
use App\Modules\Logistics\Domain\ShipmentStatus;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;

/**
 * GET /api/v1/logistics/exceptions — derived list of holds, rolled bookings,
 * and damage-related events. Jobs are excluded.
 */
final class ListExceptionsQuery
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'logistics.view');

        $companyId = $this->companyContext->companyId();
        $rows = [];

        $holds = Shipment::query()
            ->where('company_id', $companyId)
            ->where('status', ShipmentStatus::CUSTOMS_HOLD)
            ->get();

        foreach ($holds as $s) {
            $rows[] = [
                'reference' => $s->shipment_no,
                'referenceId' => $s->id,
                'type' => 'shipment',
                'exception' => 'Customs hold',
                'detail' => 'Shipment is on customs hold',
            ];
        }

        $rolled = Consignment::query()
            ->where('company_id', $companyId)
            ->where('status', ConsignmentStatus::ROLLED)
            ->with('shipment')
            ->get();

        foreach ($rolled as $c) {
            $rows[] = [
                'reference' => $c->consignment_no,
                'referenceId' => $c->id,
                'type' => 'consignment',
                'exception' => 'Rolled',
                'detail' => 'Booking rolled — track revised ETA',
            ];
        }

        $customs = CustomsDeclaration::query()
            ->where('company_id', $companyId)
            ->where('status', '!=', 'Cleared')
            ->whereNotNull('free_time_end')
            ->where('free_time_end', '<=', now()->addDays(3))
            ->get();

        foreach ($customs as $d) {
            $rows[] = [
                'reference' => $d->declaration_no,
                'referenceId' => $d->id,
                'type' => 'customs',
                'exception' => 'Free time ending',
                'detail' => 'Free time ends '.$d->free_time_end?->format('Y-m-d'),
            ];
        }

        return ['data' => $rows];
    }
}
