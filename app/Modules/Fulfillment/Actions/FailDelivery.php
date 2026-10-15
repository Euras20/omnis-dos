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
 * POST /api/v1/delivery-notes/{id}/fail — record failed attempt (dispatched → failed).
 */
final class FailDelivery
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
        $reason = isset($data['reason']) ? trim((string) $data['reason']) : '';
        $noteText = isset($data['note']) ? trim((string) $data['note']) : null;

        if ($reason === '') {
            throw new BusinessRuleException('reason is required.');
        }

        return DB::transaction(function () use ($companyId, $id, $reason, $noteText) {
            $note = DeliveryNote::query()
                ->where('company_id', $companyId)
                ->where('id', $id)
                ->lockForUpdate()
                ->first();

            if ($note === null) {
                throw new NotFoundException('Delivery note not found.');
            }
            if ($note->status !== DeliveryStatus::DISPATCHED) {
                throw new BusinessRuleException(
                    "Only dispatched deliveries can be marked failed (current: {$note->status}).",
                );
            }

            $note->status = DeliveryStatus::FAILED;
            $note->fail_reason = $reason;
            $note->attempt = (int) $note->attempt + 1;
            if ($noteText !== null && $noteText !== '') {
                $note->note = $noteText;
            }
            $note->save();

            $this->auditWriter->write(
                action: 'delivery_note.failed',
                entityType: 'delivery_note',
                entityId: $note->id,
                detail: [
                    'deliveryNo' => $note->delivery_no,
                    'reason' => $reason,
                    'attempt' => $note->attempt,
                ],
            );

            $this->outboxWriter->record([
                'aggregate_type' => 'delivery_note',
                'aggregate_id' => $note->id,
                'event_type' => 'delivery_note.failed',
                'payload' => ['status' => DeliveryStatus::FAILED, 'reason' => $reason],
            ]);

            return [
                'id' => $note->id,
                'deliveryNo' => $note->delivery_no,
                'status' => $note->status,
                'failReason' => $note->fail_reason,
                'attempt' => $note->attempt,
                'rowVersion' => $note->row_version,
            ];
        });
    }
}
