<?php

declare(strict_types=1);

namespace App\Modules\Platform\Actions;

use App\Exceptions\BusinessRuleException;
use App\Models\Holiday;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use App\Shared\Support\Uuid;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/calendars/holidays — add a public holiday.
 */
final class AddHoliday
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
        $this->permissionChecker->assertCan($actorId, 'calendars.manage');

        $companyId = $this->companyContext->companyId();
        $date = (string) ($data['date'] ?? '');
        $name = trim((string) ($data['name'] ?? ''));

        if ($date === '' || $name === '') {
            throw new BusinessRuleException('date and name are required.');
        }

        $year = (int) substr($date, 0, 4);

        return DB::transaction(function () use ($companyId, $date, $name, $year, $actorId): array {
            $existing = Holiday::query()
                ->where('company_id', $companyId)
                ->where('holiday_date', $date)
                ->first();

            if ($existing !== null) {
                throw new BusinessRuleException("A holiday already exists on {$date}.");
            }

            $holiday = Holiday::query()->create([
                'id' => Uuid::v7(),
                'company_id' => $companyId,
                'holiday_date' => $date,
                'name' => $name,
                'year' => $year,
            ]);

            $this->auditWriter->write(
                'holiday.added',
                'holiday',
                $holiday->id,
                ['date' => $date, 'name' => $name],
                $actorId,
            );

            return [
                'id' => $holiday->id,
                'date' => $holiday->holiday_date->format('Y-m-d'),
                'name' => $holiday->name,
                'year' => $holiday->year,
            ];
        });
    }
}
