<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Loan extends Model
{
    use BelongsToCompany;

    protected $table = 'loan';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'disbursement_date' => 'date',
            'principal_minor' => 'integer',
            'annual_rate_pct' => 'decimal:4',
            'term_months' => 'integer',
        ];
    }

    public function instalments(): HasMany
    {
        return $this->hasMany(LoanInstalment::class, 'loan_id')->orderBy('instalment_no');
    }
}
