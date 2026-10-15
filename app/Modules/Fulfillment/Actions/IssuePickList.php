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
 * POST /api/v1/delivery-notes/{id}/pick — issue pick list (planned → picking).
 */
final class IssuePickList
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
        $pickedBy = isset($data['pickedBy']) ? trim((string) $data['pickedBy']) : null;

        return DB::transaction(function () use ($companyId, $id, $pickedBy) {
            $note = DeliveryNote::query()
                ->where('company_id', $companyId)
                ->where('id', $id)
                ->lockForUpdate()
                ->first();

            if ($note === null) {
                throw new NotFoundException('Delivery note not found.');
            }
            if ($note->status !== DeliveryStatus::PLANNED) {
                throw new BusinessRuleException(
                    "Only planned deliveries can be issued for picking (current: {$note->status}).",
                );
            }

            $note->status = DeliveryStatus::PICKING;
            $note->picked_by = $pickedBy ?: $note->picked_by;
            $note->save();

            $this->auditWriter->write(
                action: 'delivery_note.pick_issued',
                entityType: 'delivery_note',
                entityId: $note->id,
                detail: ['deliveryNo' => $note->delivery_no, 'pickedBy' => $note->picked_by],
            );

            $this->outboxWriter->record([
                'aggregate_type' => 'delivery_note',
                'aggregate_id' => $note->id,
                'event_type' => 'delivery_note.pick_issued',
                'payload' => ['status' => DeliveryStatus::PICKING],
            ]);

            return [
                'id' => $note->id,
                'deliveryNo' => $note->delivery_no,
                'status' => $note->status,
                'pickedBy' => $note->picked_by,
                'rowVersion' => $note->row_version,
            ];
        });
    }
}
