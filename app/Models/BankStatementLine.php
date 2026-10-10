<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class BankStatementLine extends Model
{
    use BelongsToCompany;

    protected $table = 'bank_statement_line';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'txn_date' => 'date',
            'amount_minor' => 'integer',
            'matched_at' => 'datetime',
        ];
    }
}
