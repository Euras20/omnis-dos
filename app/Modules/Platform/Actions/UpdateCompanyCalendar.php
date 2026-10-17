<?php

declare(strict_types=1);

namespace App\Modules\Platform\Actions;

use App\Models\CompanyCalendar;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use App\Shared\Support\Uuid;
use Illuminate\Support\Facades\DB;

/**
 * PUT /api/v1/calendars — update working days and shifts.
 * Fiscal year start remains on the company record.
 */
final class UpdateCompanyCalendar
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

        return DB::transaction(function () use ($companyId, $data, $actorId): array {
            $calendar = CompanyCalendar::query()
                ->where('company_id', $companyId)
                ->first();

            $payload = [
                'working_days_factory' => $data['workingDaysFactory']
                    ?? $calendar?->working_days_factory
                    ?? ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'],
                'working_days_office' => $data['workingDaysOffice']
                    ?? $calendar?->working_days_office
                    ?? ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'],
                'shifts' => $data['shifts'] ?? $calendar?->shifts ?? [
                    ['name' => 'Day', 'start' => '06:00', 'end' => '14:00'],
                    ['name' => 'Evening', 'start' => '14:00', 'end' => '22:00'],
                ],
            ];

            if ($calendar === null) {
                $calendar = CompanyCalendar::query()->create(array_merge($payload, [
                    'id' => Uuid::v7(),
                    'company_id' => $companyId,
                ]));
            } else {
                $calendar->update($payload);
            }

            $this->auditWriter->write(
                'calendar.updated',
                'company_calendar',
                $calendar->id,
                ['workingDaysFactory' => $payload['working_days_factory'], 'shifts' => count($payload['shifts'])],
                $actorId,
            );

            return [
                'id' => $calendar->id,
                'workingDays' => [
                    'factory' => $calendar->working_days_factory,
                    'office' => $calendar->working_days_office,
                ],
                'shifts' => $calendar->shifts,
            ];
        });
    }
}
