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
 * POST /api/v1/delivery-notes/{id}/reschedule — reschedule a failed delivery (failed → planned).
 */
final class RescheduleDelivery
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
        $plannedDate = (string) ($data['plannedDate'] ?? '');

        if ($plannedDate === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $plannedDate)) {
            throw new BusinessRuleException('plannedDate must be YYYY-MM-DD.');
        }

        return DB::transaction(function () use ($companyId, $id, $plannedDate) {
            $note = DeliveryNote::query()
                ->where('company_id', $companyId)
                ->where('id', $id)
                ->lockForUpdate()
                ->first();

            if ($note === null) {
                throw new NotFoundException('Delivery note not found.');
            }
            if ($note->status !== DeliveryStatus::FAILED) {
                throw new BusinessRuleException(
                    "Only failed deliveries can be rescheduled (current: {$note->status}).",
                );
            }

            $note->status = DeliveryStatus::PLANNED;
            $note->revised_date = $plannedDate;
            $note->planned_date = $plannedDate;
            $note->fail_reason = null;
            $note->save();

            $this->auditWriter->write(
                action: 'delivery_note.rescheduled',
                entityType: 'delivery_note',
                entityId: $note->id,
                detail: [
                    'deliveryNo' => $note->delivery_no,
                    'plannedDate' => $plannedDate,
                    'attempt' => $note->attempt,
                ],
            );

            $this->outboxWriter->record([
                'aggregate_type' => 'delivery_note',
                'aggregate_id' => $note->id,
                'event_type' => 'delivery_note.rescheduled',
                'payload' => ['status' => DeliveryStatus::PLANNED, 'plannedDate' => $plannedDate],
            ]);

            return [
                'id' => $note->id,
                'deliveryNo' => $note->delivery_no,
                'status' => $note->status,
                'plannedDate' => $note->planned_date?->format('Y-m-d'),
                'revisedDate' => $note->revised_date?->format('Y-m-d'),
                'attempt' => $note->attempt,
                'rowVersion' => $note->row_version,
            ];
        });
    }
}
