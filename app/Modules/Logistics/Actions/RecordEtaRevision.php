<?php

declare(strict_types=1);

namespace App\Modules\Logistics\Actions;

use App\Exceptions\NotFoundException;
use App\Models\EtaRevision;
use App\Models\Shipment;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use App\Shared\Support\Uuid;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/eta-revisions — record an ETA revision.
 * Original promise is preserved for on-time reporting.
 */
final class RecordEtaRevision
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly AuditWriter $auditWriter,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function execute(array $data): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'logistics.manage');

        $companyId = $this->companyContext->companyId();
        $shipmentId = $data['shipmentId'] ?? null;

        if ($shipmentId !== null && $shipmentId !== '') {
            $exists = Shipment::query()
                ->where('company_id', $companyId)
                ->where('id', $shipmentId)
                ->exists();

            if (! $exists) {
                throw new NotFoundException('Shipment not found.');
            }
        }

        return DB::transaction(function () use ($data, $companyId, $shipmentId) {
            $rev = new EtaRevision;
            $rev->id = Uuid::v7();
            $rev->company_id = $companyId;
            $rev->shipment_id = $shipmentId ?: null;
            $rev->sales_order_id = $data['salesOrderId'] ?? null;
            $rev->original_promise = $data['originalPromise'] ?? null;
            $rev->previous = $data['previous'] ?? null;
            $rev->revised = $data['revised'];
            $rev->source = $data['source'] ?? null;
            $rev->confidence = $data['confidence'] ?? null;
            $rev->reason = $data['reason'] ?? null;
            $rev->revised_by = $data['revisedBy'] ?? null;
            $rev->revised_at = now();
            $rev->save();

            $this->auditWriter->write(
                'eta.revised',
                'eta_revision',
                $rev->id,
                ['revised' => $rev->revised?->format('Y-m-d')],
            );

            return [
                'id' => $rev->id,
                'shipmentId' => $rev->shipment_id,
                'salesOrderId' => $rev->sales_order_id,
                'originalPromise' => $rev->original_promise?->format('Y-m-d'),
                'previous' => $rev->previous?->format('Y-m-d'),
                'revised' => $rev->revised?->format('Y-m-d'),
                'source' => $rev->source,
                'confidence' => $rev->confidence,
                'reason' => $rev->reason,
                'revisedAt' => $rev->revised_at?->toIso8601String(),
            ];
        });
    }
}
