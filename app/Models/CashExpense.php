<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashExpense extends Model
{
    use BelongsToCompany;

    protected $table = 'cash_expense';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'expense_date' => 'date',
            'gross_minor' => 'integer',
            'tax_minor' => 'integer',
            'net_minor' => 'integer',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CashExpenseLine::class, 'cash_expense_id');
    }
}
