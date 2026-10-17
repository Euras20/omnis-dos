<?php

declare(strict_types=1);

namespace App\Modules\Platform\Queries;

use App\Models\Company;
use App\Models\CompanyCalendar;
use App\Models\Holiday;
use App\Shared\Company\CompanyContext;

/**
 * GET /api/v1/calendars
 *
 * Returns the company calendar matching the Settings → Calendars demo:
 * fiscal year, working days (factory / office), shifts, and holidays
 * for the requested year (default current). Empty holidays fall back to
 * a sample Ghana public-holiday list so the screen is never blank.
 */
final class GetCompanyCalendarQuery
{
    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(?int $year = null): array
    {
        $companyId = $this->companyContext->companyId();
        $company = Company::query()->findOrFail($companyId);

        $year ??= (int) now()->format('Y');

        $calendar = CompanyCalendar::query()
            ->where('company_id', $companyId)
            ->first();

        $factory = $calendar?->working_days_factory
            ?? ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        $office = $calendar?->working_days_office
            ?? ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
        $shifts = $calendar?->shifts ?? [
            ['name' => 'Day', 'start' => '06:00', 'end' => '14:00'],
            ['name' => 'Evening', 'start' => '14:00', 'end' => '22:00'],
        ];

        $holidays = Holiday::query()
            ->where('company_id', $companyId)
            ->where('year', $year)
            ->orderBy('holiday_date')
            ->get();

        if ($holidays->isEmpty()) {
            $holidays = collect($this->sampleHolidays($year))->map(fn (array $h) => (object) [
                'id' => null,
                'holiday_date' => $h['date'],
                'name' => $h['name'],
                'year' => $year,
                'sample' => true,
            ]);
        }

        $fyStart = (int) $company->fiscal_year_start_month;
        $fyLabel = $this->fiscalYearLabel($fyStart);

        return [
            'fiscalYearStartMonth' => $fyStart,
            'fiscalYear' => $fyLabel,
            'timezone' => $company->timezone,
            'workingDays' => [
                'factory' => $factory,
                'office' => $office,
            ],
            'shifts' => $shifts,
            'year' => $year,
            'holidays' => $holidays->map(fn ($h) => [
                'id' => $h->id,
                'date' => is_string($h->holiday_date) ? $h->holiday_date : $h->holiday_date->format('Y-m-d'),
                'name' => $h->name,
                'sample' => $h->sample ?? false,
            ])->values()->all(),
            'taxCalendar' => '/api/v1/tax/filings',
        ];
    }

    private function fiscalYearLabel(int $startMonth): string
    {
        $months = [
            1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
            5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
            9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
        ];
        $start = $months[$startMonth] ?? 'January';
        $endMonth = $startMonth === 1 ? 12 : $startMonth - 1;
        $end = $months[$endMonth] ?? 'December';

        return $start . ' – ' . $end;
    }

    /**
     * Sample Ghana public holidays used when the company has none stored.
     *
     * @return list<array{date: string, name: string}>
     */
    private function sampleHolidays(int $year): array
    {
        return [
            ['date' => "{$year}-01-01", 'name' => "New Year's Day"],
            ['date' => "{$year}-03-06", 'name' => 'Independence Day'],
            ['date' => "{$year}-05-01", 'name' => 'Labour Day'],
            ['date' => "{$year}-12-25", 'name' => 'Christmas Day'],
            ['date' => "{$year}-12-26", 'name' => 'Boxing Day'],
        ];
    }
}
