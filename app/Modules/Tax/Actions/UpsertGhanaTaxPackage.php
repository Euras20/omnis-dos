<?php

declare(strict_types=1);

namespace App\Modules\Tax\Actions;

use App\Exceptions\BusinessRuleException;
use App\Models\TaxRuleVersion;
use App\Modules\Tax\Queries\GetGhanaTaxPackageQuery;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use App\Shared\Support\Uuid;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/tax/ghana — save a new versioned Ghana tax package.
 *
 * Closes any open GHANA version whose effective_from is earlier than the new
 * effectiveFrom. The full package is stored in tax_rule_version.components.
 */
final class UpsertGhanaTaxPackage
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

        $effectiveFrom = (string) ($data['effectiveFrom'] ?? '');
        if ($effectiveFrom === '') {
            throw new BusinessRuleException('effectiveFrom is required.');
        }

        $source = isset($data['package']) && is_array($data['package']) ? $data['package'] : $data;
        $package = $this->normalisePackage($source);

        return DB::transaction(function () use ($package, $companyId, $actorId, $effectiveFrom, $data) {
            TaxRuleVersion::query()
                ->where('company_id', $companyId)
                ->where('code', GetGhanaTaxPackageQuery::CODE)
                ->whereNull('effective_to')
                ->where('effective_from', '<', $effectiveFrom)
                ->update([
                    'effective_to' => date('Y-m-d', strtotime($effectiveFrom.' -1 day')),
                ]);

            $rule = new TaxRuleVersion;
            $rule->id = Uuid::v7();
            $rule->company_id = $companyId;
            $rule->code = GetGhanaTaxPackageQuery::CODE;
            $rule->name = trim((string) ($data['name'] ?? 'Ghana tax package'));
            $rule->effective_from = $effectiveFrom;
            $rule->effective_to = $data['effectiveTo'] ?? null;
            $rule->rate_percent = (float) ($package['vat'] ?? 0);
            $rule->components = $package;
            $rule->notes = $package['note'] ?? null;
            $rule->is_active = true;
            $rule->save();

            $this->auditWriter->write(
                action: 'tax_rule_version.ghana_updated',
                entityType: 'tax_rule_version',
                entityId: $rule->id,
                detail: [
                    'code' => $rule->code,
                    'effectiveFrom' => $effectiveFrom,
                    'vat' => $package['vat'] ?? null,
                ],
                actorId: $actorId,
            );

            return [
                'id' => (string) $rule->id,
                'code' => $rule->code,
                'name' => $rule->name,
                'effectiveFrom' => $rule->effective_from?->format('Y-m-d'),
                'effectiveTo' => $rule->effective_to?->format('Y-m-d'),
                'isDefault' => false,
                'rowVersion' => (int) $rule->row_version,
                'package' => $package,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function normalisePackage(array $raw): array
    {
        $defaults = GetGhanaTaxPackageQuery::defaults();
        $package = $defaults;

        foreach (['method', 'law', 'note'] as $key) {
            if (isset($raw[$key]) && is_string($raw[$key])) {
                $package[$key] = $raw[$key];
            }
        }
        foreach (['vat', 'nhil', 'getfund', 'covid', 'nonResident', 'ssnitMin', 'ssnitMax', 'ssnitEmp', 'ssnitEr', 'tier1', 'tier2', 'otJunior1', 'otJunior2', 'otCapPctBasic', 'bonusRate', 'bonusCapPctAnnual', 'cit'] as $key) {
            if (isset($raw[$key]) && is_numeric($raw[$key])) {
                $package[$key] = (float) $raw[$key];
            }
        }
        if (array_key_exists('otQualifyMaxBasic', $raw)) {
            $package['otQualifyMaxBasic'] = is_numeric($raw['otQualifyMaxBasic']) ? (float) $raw['otQualifyMaxBasic'] : null;
        }
        if (isset($raw['vatThresholdMinor']) && is_numeric($raw['vatThresholdMinor'])) {
            $package['vatThresholdMinor'] = (int) $raw['vatThresholdMinor'];
        }
        if (isset($raw['paye']) && is_array($raw['paye'])) {
            $package['paye'] = $this->normalisePaye($raw['paye']);
        }

        return $package;
    }

    /**
     * @param  list<mixed>  $bands
     * @return list<array{upTo: float|null, rate: float}>
     */
    private function normalisePaye(array $bands): array
    {
        $out = [];
        foreach ($bands as $band) {
            if (! is_array($band) || ! isset($band['rate'])) {
                continue;
            }
            $upTo = $band['upTo'] ?? null;
            $out[] = [
                'upTo' => is_numeric($upTo) ? (float) $upTo : null,
                'rate' => (float) $band['rate'],
            ];
        }

        return $out !== [] ? $out : GetGhanaTaxPackageQuery::defaults()['paye'];
    }
}
