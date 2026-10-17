<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Http\Requests\Platform\AddHolidayRequest;
use App\Http\Requests\Platform\RecordTaxFilingRequest;
use App\Http\Requests\Platform\UpdateCalendarRequest;
use App\Modules\Platform\Actions\AddHoliday;
use App\Modules\Platform\Actions\RecordTaxFiling;
use App\Modules\Platform\Actions\RemoveHoliday;
use App\Modules\Platform\Actions\UpdateCompanyCalendar;
use App\Modules\Platform\Queries\GetCompanyCalendarQuery;
use App\Modules\Platform\Queries\ListTaxFilingsQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CalendarController
{
    public function __construct(
        private readonly GetCompanyCalendarQuery $calendar,
        private readonly UpdateCompanyCalendar $updateCalendar,
        private readonly AddHoliday $addHoliday,
        private readonly RemoveHoliday $removeHoliday,
        private readonly ListTaxFilingsQuery $filings,
        private readonly RecordTaxFiling $recordFiling,
    ) {
    }

    /**
     * GET /api/v1/calendars
     */
    public function show(Request $request): JsonResponse
    {
        $year = $request->query('year') !== null ? (int) $request->query('year') : null;

        return response()->json($this->calendar->execute($year));
    }

    /**
     * PUT /api/v1/calendars
     */
    public function update(UpdateCalendarRequest $request): JsonResponse
    {
        return response()->json($this->updateCalendar->execute($request->validated()));
    }

    /**
     * POST /api/v1/calendars/holidays
     */
    public function storeHoliday(AddHolidayRequest $request): JsonResponse
    {
        return response()->json($this->addHoliday->execute($request->validated()), 201);
    }

    /**
     * DELETE /api/v1/calendars/holidays/{id}
     */
    public function destroyHoliday(string $id): JsonResponse
    {
        $this->removeHoliday->execute($id);

        return response()->json(['deleted' => true]);
    }

    /**
     * GET /api/v1/tax/filings
     */
    public function filings(): JsonResponse
    {
        return response()->json($this->filings->execute());
    }

    /**
     * POST /api/v1/tax/filings/{id}/record
     */
    public function recordFiling(string $id, RecordTaxFilingRequest $request): JsonResponse
    {
        return response()->json($this->recordFiling->execute($id, $request->validated()));
    }
}
