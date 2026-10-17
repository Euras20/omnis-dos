<?php

declare(strict_types=1);

namespace App\Modules\Platform\Actions;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\NotFoundException;
use App\Models\TaxFiling;
use App\Shared\Audit\AuditWriter;
use App\Shared\Company\CompanyContext;
use App\Shared\Permissions\PermissionChecker;
use App\Shared\Support\Uuid;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/tax/filings/{id}/record — record a submission (simulated).
 * Creates the filing row if the caller passes a sample code that does not yet exist.
 */
final class RecordTaxFiling
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
    public function execute(string $idOrCode, array $data): array
    {
        $actorId = $this->companyContext->userId();
        $this->permissionChecker->assertCan($actorId, 'tax.manage');

        $companyId = $this->companyContext->companyId();
        $ref = trim((string) ($data['ref'] ?? ''));
        $payment = (string) ($data['payment'] ?? 'Not paid');

        if ($ref === '') {
            throw new BusinessRuleException('Submission reference is required.');
        }

        return DB::transaction(function () use ($idOrCode, $companyId, $ref, $payment, $actorId): array {
            $filing = TaxFiling::query()
                ->where('company_id', $companyId)
                ->where(function ($q) use ($idOrCode): void {
                    $q->where('id', $idOrCode)->orWhere('code', $idOrCode);
                })
                ->first();

            if ($filing === null) {
                // Materialise a sample code so the demo can record against it.
                $sample = $this->findSample($idOrCode);
                if ($sample === null) {
                    throw new NotFoundException('Tax filing not found.');
                }

                $filing = TaxFiling::query()->create([
                    'id' => Uuid::v7(),
                    'company_id' => $companyId,
                    'code' => $sample['code'],
                    'tax' => $sample['tax'],
                    'period' => $sample['period'],
                    'due_date' => $sample['due'],
                    'revision' => $sample['revision'],
                    'filing_status' => 'Not started',
                    'payment_status' => 'Not paid',
                ]);
            }

            $filing->update([
                'filing_status' => 'Filed',
                'payment_status' => $payment,
                'submission_ref' => $ref . ' (simulated)',
                'revision' => $filing->revision + 1,
            ]);

            $this->auditWriter->write(
                'tax.filing.recorded',
                'tax_filing',
                $filing->id,
                ['code' => $filing->code, 'ref' => $filing->submission_ref],
                $actorId,
            );

            return [
                'id' => $filing->id,
                'code' => $filing->code,
                'filing' => $filing->filing_status,
                'payment' => $filing->payment_status,
                'ref' => $filing->submission_ref,
                'revision' => $filing->revision,
            ];
        });
    }

    /**
     * @return array{code: string, tax: string, period: string, due: string, revision: int}|null
     */
    private function findSample(string $code): ?array
    {
        $samples = [
            'TX-VAT-2026-08' => ['code' => 'TX-VAT-2026-08', 'tax' => 'VAT return', 'period' => 'August 2026', 'due' => '2026-09-30', 'revision' => 1],
            'TX-WHT-2026-08' => ['code' => 'TX-WHT-2026-08', 'tax' => 'Withholding return', 'period' => 'August 2026', 'due' => '2026-09-15', 'revision' => 1],
            'TX-PAYE-2026-08' => ['code' => 'TX-PAYE-2026-08', 'tax' => 'PAYE return', 'period' => 'August 2026', 'due' => '2026-09-15', 'revision' => 1],
            'TX-PAYE-2026-09' => ['code' => 'TX-PAYE-2026-09', 'tax' => 'PAYE return', 'period' => 'September 2026', 'due' => '2026-10-15', 'revision' => 0],
            'TX-CIT-2026-Q3' => ['code' => 'TX-CIT-2026-Q3', 'tax' => 'Income tax instalment', 'period' => 'Q3 2026', 'due' => '2026-09-30', 'revision' => 1],
        ];

        return $samples[$code] ?? null;
    }
}
