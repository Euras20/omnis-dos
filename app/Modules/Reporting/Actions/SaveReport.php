<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Actions;

use App\Models\SavedReport;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use App\Shared\Support\Uuid;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/reports/saved — save a custom report definition.
 */
final class SaveReport
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
        $this->permissionChecker->assertCan($actorId, 'reports.financial');

        $companyId = $this->companyContext->companyId();

        return DB::transaction(function () use ($companyId, $actorId, $data): array {
            $report = SavedReport::query()->create([
                'id' => Uuid::v7(),
                'company_id' => $companyId,
                'owner_id' => $actorId,
                'name' => trim((string) $data['name']),
                'report_key' => (string) $data['key'],
                'settings' => $data['settings'] ?? ['period' => $data['period'] ?? 'this_month', 'currency' => $data['currency'] ?? null],
                'is_shared' => (bool) ($data['shared'] ?? false),
            ]);

            $this->auditWriter->write(
                'report.saved',
                'saved_report',
                $report->id,
                ['name' => $report->name, 'key' => $report->report_key],
                $actorId,
            );

            return [
                'id' => $report->id,
                'name' => $report->name,
                'key' => $report->report_key,
                'settings' => $report->settings,
                'shared' => $report->is_shared,
            ];
        });
    }
}
