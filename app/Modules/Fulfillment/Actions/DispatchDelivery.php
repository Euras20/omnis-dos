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
 * POST /api/v1/delivery-notes/{id}/dispatch — dispatch (packed → dispatched).
 */
final class DispatchDelivery
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
        $vehicle = isset($data['vehicle']) ? trim((string) $data['vehicle']) : null;
        $driver = isset($data['driver']) ? trim((string) $data['driver']) : null;
        $carrier = isset($data['carrier']) ? trim((string) $data['carrier']) : null;
        $route = isset($data['route']) ? trim((string) $data['route']) : null;

        return DB::transaction(function () use (
            $companyId,
            $id,
            $vehicle,
            $driver,
            $carrier,
            $route,
        ) {
            $note = DeliveryNote::query()
                ->where('company_id', $companyId)
                ->where('id', $id)
                ->lockForUpdate()
                ->first();

            if ($note === null) {
                throw new NotFoundException('Delivery note not found.');
            }
            if ($note->status !== DeliveryStatus::PACKED) {
                throw new BusinessRuleException(
                    "Only packed deliveries can be dispatched (current: {$note->status}).",
                );
            }

            $note->status = DeliveryStatus::DISPATCHED;
            $note->vehicle = $vehicle ?: $note->vehicle;
            $note->driver = $driver ?: $note->driver;
            $note->carrier = $carrier ?: $note->carrier;
            $note->route = $route ?: $note->route;
            $note->dispatch_at = now();
            $note->save();

            $this->auditWriter->write(
                action: 'delivery_note.dispatched',
                entityType: 'delivery_note',
                entityId: $note->id,
                detail: [
                    'deliveryNo' => $note->delivery_no,
                    'vehicle' => $note->vehicle,
                    'driver' => $note->driver,
                    'dispatchAt' => $note->dispatch_at?->toIso8601String(),
                ],
            );

            $this->outboxWriter->record([
                'aggregate_type' => 'delivery_note',
                'aggregate_id' => $note->id,
                'event_type' => 'delivery_note.dispatched',
                'payload' => ['status' => DeliveryStatus::DISPATCHED],
            ]);

            return [
                'id' => $note->id,
                'deliveryNo' => $note->delivery_no,
                'status' => $note->status,
                'vehicle' => $note->vehicle,
                'driver' => $note->driver,
                'carrier' => $note->carrier,
                'route' => $note->route,
                'dispatchAt' => $note->dispatch_at?->toIso8601String(),
                'rowVersion' => $note->row_version,
            ];
        });
    }
}
