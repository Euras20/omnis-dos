<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class BankReconciliation extends Model
{
    use BelongsToCompany;

    protected $table = 'bank_reconciliation';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'statement_date' => 'date',
            'statement_closing_minor' => 'integer',
            'book_balance_minor' => 'integer',
            'difference_minor' => 'integer',
            'prepared_at' => 'datetime',
            'approved_at' => 'datetime',
            'snapshot' => 'array',
        ];
    }
}
