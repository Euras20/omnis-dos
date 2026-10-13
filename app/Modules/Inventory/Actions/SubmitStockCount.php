<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\NotFoundException;
use App\Models\StockCount;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/stock/counts/{id}/submit — stock keeper marks count ready for supervisor.
 */
final class SubmitStockCount
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly AuditWriter $auditWriter,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(string $id): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'stock.move');

        return DB::transaction(function () use ($id, $actorId) {
            $count = StockCount::query()->where('id', $id)->lockForUpdate()->first();
            if ($count === null) {
                throw new NotFoundException('Stock count not found.');
            }
            if ($count->status !== 'draft') {
                throw new BusinessRuleException('Only draft counts can be submitted.');
            }

            $count->status = 'awaiting_supervisor';
            $count->counted_by = $actorId;
            $count->counted_at = now();
            $count->save();

            $this->auditWriter->write(
                action: 'stock_count.submitted',
                entityType: 'stock_count',
                entityId: $count->id,
                detail: ['countNo' => $count->count_no],
                actorId: $actorId,
            );

            return CreateStockCount::toArray(
                StockCount::query()->with(['lines.item', 'location'])->findOrFail($count->id)
            );
        });
    }
}
