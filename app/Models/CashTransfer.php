<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class CashTransfer extends Model
{
    use BelongsToCompany;

    protected $table = 'cash_transfer';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'transfer_date' => 'date',
            'amount_minor' => 'integer',
        ];
    }
}
