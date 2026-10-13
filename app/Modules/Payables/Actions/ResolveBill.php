<?php

declare(strict_types=1);

namespace App\Modules\Payables\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\NotFoundException;
use App\Models\Bill;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Outbox\OutboxWriter;
use App\Shared\Permissions\PermissionChecker;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/bills/{id}/resolve
 *
 * Clears an operational mismatch hold. Bill must already be status=mismatch
 * and already have its original journal (posted). Resolve does not create a
 * second journal or stock movement — it only releases the hold to posted.
 */
final class ResolveBill
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
    public function execute(string $id, array $data = []): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'ap.bill.post');

        $companyId = $this->companyContext->companyId();
        $note = isset($data['note']) ? trim((string) $data['note']) : null;

        return DB::transaction(function () use ($id, $companyId, $actorId, $note) {
            $bill = Bill::query()
                ->where('company_id', $companyId)
                ->where('id', $id)
                ->lockForUpdate()
                ->first();

            if ($bill === null) {
                throw new NotFoundException('Bill not found.');
            }

            if ($bill->status !== 'mismatch') {
                throw new BusinessRuleException(
                    "Only mismatch bills can be resolved (current status: {$bill->status})."
                );
            }

            $bill->status = 'posted';
            if ($note !== null && $note !== '') {
                $bill->memo = trim(($bill->memo ? $bill->memo."\n" : '').'Resolved: '.$note);
            }
            $bill->save();

            $this->auditWriter->write(
                action: 'bill.resolved',
                entityType: 'bill',
                entityId: $bill->id,
                detail: [
                    'billNo' => $bill->bill_no,
                    'note' => $note,
                ],
                actorId: $actorId,
            );

            $this->outboxWriter->record([
                'aggregate_type' => 'bill',
                'aggregate_id' => $bill->id,
                'event_type' => 'bill.resolved',
                'payload' => [
                    'billId' => $bill->id,
                    'billNo' => $bill->bill_no,
                ],
            ]);

            return [
                'id' => $bill->id,
                'billNo' => $bill->bill_no,
                'status' => $bill->status,
            ];
        });
    }
}
