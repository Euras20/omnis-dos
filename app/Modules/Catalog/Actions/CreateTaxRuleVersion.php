<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Exceptions\BusinessRuleException;
use App\Models\TaxRuleVersion;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use App\Shared\Support\Uuid;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/tax/rules — add an approved tax rule version; closes the previous open version for the same code.
 */
final class CreateTaxRuleVersion
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
        $this->permissionChecker->assertCan($actorId, 'tax.manage');
        $companyId = $this->companyContext->companyId();
        $code = strtoupper(trim((string) $data['code']));
        $effectiveFrom = (string) $data['effectiveFrom'];

        if ($effectiveFrom === '') {
            throw new BusinessRuleException('effectiveFrom is required.');
        }

        return DB::transaction(function () use ($data, $companyId, $actorId, $code, $effectiveFrom) {
            // Close any open version for this code with effective_from < new date.
            TaxRuleVersion::query()
                ->where('company_id', $companyId)
                ->where('code', $code)
                ->whereNull('effective_to')
                ->where('effective_from', '<', $effectiveFrom)
                ->update([
                    'effective_to' => date('Y-m-d', strtotime($effectiveFrom.' -1 day')),
                ]);

            $rule = new TaxRuleVersion;
            $rule->id = Uuid::v7();
            $rule->company_id = $companyId;
            $rule->code = $code;
            $rule->name = trim((string) $data['name']);
            $rule->effective_from = $effectiveFrom;
            $rule->effective_to = $data['effectiveTo'] ?? null;
            $rule->rate_percent = $data['ratePercent'] ?? $data['rate'] ?? 0;
            $rule->components = $data['components'] ?? null;
            $rule->notes = $data['notes'] ?? null;
            $rule->is_active = true;
            $rule->save();

            $this->auditWriter->write(
                action: 'tax_rule_version.created',
                entityType: 'tax_rule_version',
                entityId: $rule->id,
                detail: [
                    'code' => $rule->code,
                    'effectiveFrom' => $effectiveFrom,
                    'ratePercent' => (float) $rule->rate_percent,
                ],
                actorId: $actorId,
            );

            return self::toArray($rule);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public static function toArray(TaxRuleVersion $rule): array
    {
        return [
            'id' => (string) $rule->id,
            'code' => $rule->code,
            'name' => $rule->name,
            'effectiveFrom' => $rule->effective_from?->format('Y-m-d'),
            'effectiveTo' => $rule->effective_to?->format('Y-m-d'),
            'ratePercent' => (float) $rule->rate_percent,
            'components' => $rule->components,
            'notes' => $rule->notes,
            'isActive' => (bool) $rule->is_active,
            'rowVersion' => (int) $rule->row_version,
        ];
    }
}
