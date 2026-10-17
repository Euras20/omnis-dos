<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollRun extends Model
{
    use BelongsToCompany;

    protected $table = 'payroll_run';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'pay_date' => 'date',
            'gross_total_minor' => 'integer',
            'ssf_employee_minor' => 'integer',
            'ssf_employer_minor' => 'integer',
            'paye_minor' => 'integer',
            'ot_tax_total_minor' => 'integer',
            'net_total_minor' => 'integer',
            'exceptions' => 'array',
            'approved_at' => 'datetime',
            'posted_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PayrollLine::class, 'payroll_run_id');
    }
}
