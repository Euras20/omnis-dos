<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class CashExpenseLine extends Model
{
    use BelongsToCompany;

    protected $table = 'cash_expense_line';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'tax_minor' => 'integer',
        ];
    }
}
