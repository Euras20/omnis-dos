<?php

declare(strict_types=1);

namespace App\Modules\Platform\Queries;

use App\Models\TaxFiling;
use App\Shared\Company\CompanyContext;

/**
 * GET /api/v1/tax/filings — tax filing calendar.
 * Returns stored filings or the frontend sample set when none exist.
 */
final class ListTaxFilingsQuery
{
    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {
    }

    /**
     * @return array{filings: list<array<string, mixed>>}
     */
    public function execute(): array
    {
        $companyId = $this->companyContext->companyId();

        $rows = TaxFiling::query()
            ->where('company_id', $companyId)
            ->orderBy('due_date')
            ->get();

        if ($rows->isEmpty()) {
            return ['filings' => $this->sampleFilings()];
        }

        return [
            'filings' => $rows->map(fn (TaxFiling $f) => [
                'id' => $f->id,
                'code' => $f->code,
                'tax' => $f->tax,
                'period' => $f->period,
                'due' => $f->due_date->format('Y-m-d'),
                'revision' => $f->revision,
                'filing' => $f->filing_status,
                'payment' => $f->payment_status,
                'ref' => $f->submission_ref ?? '',
            ])->all(),
        ];
    }

    /**
     * Sample filings matching the frontend S.filings demo.
     *
     * @return list<array<string, mixed>>
     */
    private function sampleFilings(): array
    {
        return [
            ['id' => null, 'code' => 'TX-VAT-2026-08', 'tax' => 'VAT return', 'period' => 'August 2026', 'due' => '2026-09-30', 'revision' => 1, 'filing' => 'Prepared', 'payment' => 'Not paid', 'ref' => '', 'sample' => true],
            ['id' => null, 'code' => 'TX-WHT-2026-08', 'tax' => 'Withholding return', 'period' => 'August 2026', 'due' => '2026-09-15', 'revision' => 1, 'filing' => 'Filed', 'payment' => 'Paid', 'ref' => 'SIM-WHT-8812 (simulated)', 'sample' => true],
            ['id' => null, 'code' => 'TX-PAYE-2026-08', 'tax' => 'PAYE return', 'period' => 'August 2026', 'due' => '2026-09-15', 'revision' => 1, 'filing' => 'Filed', 'payment' => 'Paid', 'ref' => 'SIM-PAYE-2231 (simulated)', 'sample' => true],
            ['id' => null, 'code' => 'TX-PAYE-2026-09', 'tax' => 'PAYE return', 'period' => 'September 2026', 'due' => '2026-10-15', 'revision' => 0, 'filing' => 'Not started', 'payment' => 'Not paid', 'ref' => '', 'sample' => true],
            ['id' => null, 'code' => 'TX-CIT-2026-Q3', 'tax' => 'Income tax instalment', 'period' => 'Q3 2026', 'due' => '2026-09-30', 'revision' => 1, 'filing' => 'Prepared', 'payment' => 'Not paid', 'ref' => '', 'sample' => true],
        ];
    }
}
