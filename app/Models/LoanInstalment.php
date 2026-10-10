<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoanInstalment extends Model
{
    use BelongsToCompany;

    protected $table = 'loan_instalment';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'principal_minor' => 'integer',
            'interest_minor' => 'integer',
            'total_minor' => 'integer',
            'paid_date' => 'date',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class, 'loan_id');
    }
}
