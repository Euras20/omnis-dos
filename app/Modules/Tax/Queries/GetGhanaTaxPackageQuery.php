<?php

declare(strict_types=1);

namespace App\Modules\Tax\Queries;

use App\Models\TaxRuleVersion;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;

/**
 * GET /api/v1/tax/ghana
 *
 * Current effective Ghana tax package (VAT Act 1151 components + PAYE bands +
 * SSNIT / payroll rates). Stored as a tax_rule_version with code = GHANA.
 * Returns the hardcoded 2026 defaults when no version has been saved.
 */
final class GetGhanaTaxPackageQuery
{
    public const CODE = 'GHANA';

    /**
     * Defaults matching the frontend S.tax object (Ghana 2026 as understood).
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'method' => 'act1151',
            'vat' => 15.0,
            'nhil' => 2.5,
            'getfund' => 2.5,
            'vatThresholdMinor' => 75_000_000,
            'law' => 'Value Added Tax Act, 2025 (Act 1151)',
            'paye' => [
                ['upTo' => 490, 'rate' => 0],
                ['upTo' => 110, 'rate' => 5],
                ['upTo' => 130, 'rate' => 10],
                ['upTo' => 3166.67, 'rate' => 17.5],
                ['upTo' => 16000, 'rate' => 25],
                ['upTo' => 30520, 'rate' => 30],
                ['upTo' => null, 'rate' => 35],
            ],
            'nonResident' => 25.0,
            'ssnitMin' => 587.80,
            'ssnitMax' => 69000.0,
            'otQualifyMaxBasic' => null,
            'ssnitEmp' => 5.5,
            'ssnitEr' => 13.0,
            'tier1' => 13.5,
            'tier2' => 5.0,
            'otJunior1' => 5.0,
            'otJunior2' => 10.0,
            'otCapPctBasic' => 50.0,
            'bonusRate' => 5.0,
            'bonusCapPctAnnual' => 15.0,
            'cit' => 25.0,
            'note' => 'Defaults reflect Ghana rules as understood for 2026. Confirm against current GRA guidance before filing.',
        ];
    }

    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly PermissionChecker $permissionChecker,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(?string $asOf = null): array
    {
        $actorId = $this->companyContext->userId();
        if (! $this->permissionChecker->can($actorId, 'ledger.read')
            && ! $this->permissionChecker->can($actorId, 'tax.manage')
            && ! $this->permissionChecker->can($actorId, 'payroll.approve')) {
            $this->permissionChecker->assertCan($actorId, 'ledger.read');
        }

        $companyId = $this->companyContext->companyId();
        $date = $asOf ?? now()->toDateString();

        $rule = TaxRuleVersion::query()
            ->where('company_id', $companyId)
            ->where('code', self::CODE)
            ->where('effective_from', '<=', $date)
            ->where(function ($q) use ($date) {
                $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date);
            })
            ->orderByDesc('effective_from')
            ->first();

        $isDefault = $rule === null;
        $components = $isDefault ? self::defaults() : (array) ($rule->components ?? []);

        return [
            'code' => self::CODE,
            'name' => $isDefault ? 'Ghana tax package (defaults)' : $rule->name,
            'effectiveFrom' => $isDefault ? '2026-01-01' : $rule->effective_from?->format('Y-m-d'),
            'effectiveTo' => $isDefault ? null : $rule->effective_to?->format('Y-m-d'),
            'isDefault' => $isDefault,
            'id' => $isDefault ? null : (string) $rule->id,
            'rowVersion' => $isDefault ? null : (int) $rule->row_version,
            'package' => $components,
        ];
    }
}
