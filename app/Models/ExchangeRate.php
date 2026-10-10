<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExchangeRate extends Model
{
    use BelongsToCompany;

    protected $table = 'exchange_rate';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'currency',
        'rate_date',
        'buy_rate',
        'sell_rate',
        'mid_rate',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'rate_date' => 'date',
            'buy_rate' => 'decimal:8',
            'sell_rate' => 'decimal:8',
            'mid_rate' => 'decimal:8',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }
}
