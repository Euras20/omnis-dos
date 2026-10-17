<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-company operational calendar: working days and shifts.
 * Fiscal year start lives on company.fiscal_year_start_month.
 */
class CompanyCalendar extends Model
{
    use BelongsToCompany;

    protected $table = 'company_calendar';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'working_days_factory' => 'array',
            'working_days_office' => 'array',
            'shifts' => 'array',
        ];
    }
}
