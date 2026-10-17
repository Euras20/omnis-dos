<?php

declare(strict_types=1);

namespace App\Modules\Platform\Actions;

use App\Exceptions\NotFoundException;
use App\Models\Holiday;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use Illuminate\Support\Facades\DB;

/**
 * DELETE /api/v1/calendars/holidays/{id}
 */
final class RemoveHoliday
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly AuditWriter $auditWriter,
    ) {
    }

    public function execute(string $id): void
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'calendars.manage');

        $companyId = $this->companyContext->companyId();

        DB::transaction(function () use ($id, $companyId, $actorId): void {
            $holiday = Holiday::query()
                ->where('company_id', $companyId)
                ->where('id', $id)
                ->first();

            if ($holiday === null) {
                throw new NotFoundException('Holiday not found.');
            }

            $detail = [
                'date' => $holiday->holiday_date->format('Y-m-d'),
                'name' => $holiday->name,
            ];

            $holiday->delete();

            $this->auditWriter->write(
                'holiday.removed',
                'holiday',
                $id,
                $detail,
                $actorId,
            );
        });
    }
}
