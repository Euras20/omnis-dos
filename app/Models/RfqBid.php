<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RfqBid extends Model
{
    use BelongsToCompany;

    protected $table = 'rfq_bid';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'rfq_id',
        'supplier_id',
        'bid_date',
        'currency',
        'total_minor',
        'status',
        'line_prices',
        'memo',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'bid_date' => 'date',
            'total_minor' => 'integer',
            'line_prices' => 'array',
        ];
    }

    public function rfq(): BelongsTo
    {
        return $this->belongsTo(Rfq::class, 'rfq_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'supplier_id');
    }
}
