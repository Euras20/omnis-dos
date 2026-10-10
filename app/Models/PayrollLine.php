<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollLine extends Model
{
    use BelongsToCompany;

    protected $table = 'payroll_line';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'basic_minor' => 'integer',
            'overtime_hours' => 'integer',
            'overtime_minor' => 'integer',
            'allowances_minor' => 'integer',
            'gross_minor' => 'integer',
            'ssf_employee_minor' => 'integer',
            'ssf_employer_minor' => 'integer',
            'paye_minor' => 'integer',
            'other_deductions_minor' => 'integer',
            'net_minor' => 'integer',
            'is_frozen' => 'boolean',
            'calculation_snapshot' => 'array',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
}
