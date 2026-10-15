<?php

declare(strict_types=1);

namespace App\Modules\Fulfillment\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\NotFoundException;
use App\Models\DeliveryNote;
use App\Modules\Fulfillment\Domain\DeliveryStatus;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Outbox\OutboxWriter;
use App\Shared\Permissions\PermissionChecker;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/delivery-notes/{id}/pack — packing list (picking → packed).
 */
final class PackDelivery
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
    public function execute(string $id, array $data): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'stock.move');

        $companyId = $this->companyContext->companyId();
        $packages = isset($data['packages']) ? trim((string) $data['packages']) : '';
        $weightKg = isset($data['weightKg']) ? (float) $data['weightKg'] : null;
        $volumeM3 = isset($data['volumeM3']) ? (float) $data['volumeM3'] : null;
        $packedBy = isset($data['packedBy']) ? trim((string) $data['packedBy']) : null;

        if ($packages === '') {
            throw new BusinessRuleException('packages is required.');
        }

        return DB::transaction(function () use (
            $companyId,
            $id,
            $packages,
            $weightKg,
            $volumeM3,
            $packedBy,
        ) {
            $note = DeliveryNote::query()
                ->where('company_id', $companyId)
                ->where('id', $id)
                ->lockForUpdate()
                ->first();

            if ($note === null) {
                throw new NotFoundException('Delivery note not found.');
            }
            if ($note->status !== DeliveryStatus::PICKING) {
                throw new BusinessRuleException(
                    "Only picking deliveries can be packed (current: {$note->status}).",
                );
            }

            $note->status = DeliveryStatus::PACKED;
            $note->packages = $packages;
            $note->weight_kg = $weightKg;
            $note->volume_m3 = $volumeM3;
            $note->packed_by = $packedBy;
            $note->save();

            $this->auditWriter->write(
                action: 'delivery_note.packed',
                entityType: 'delivery_note',
                entityId: $note->id,
                detail: [
                    'deliveryNo' => $note->delivery_no,
                    'packages' => $packages,
                    'weightKg' => $weightKg,
                    'packedBy' => $packedBy,
                ],
            );

            $this->outboxWriter->record([
                'aggregate_type' => 'delivery_note',
                'aggregate_id' => $note->id,
                'event_type' => 'delivery_note.packed',
                'payload' => ['status' => DeliveryStatus::PACKED],
            ]);

            return [
                'id' => $note->id,
                'deliveryNo' => $note->delivery_no,
                'status' => $note->status,
                'packages' => $note->packages,
                'weightKg' => $note->weight_kg !== null ? (string) $note->weight_kg : null,
                'packedBy' => $note->packed_by,
                'rowVersion' => $note->row_version,
            ];
        });
    }
}
