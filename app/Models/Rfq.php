<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Rfq extends Model
{
    use BelongsToCompany;

    protected $table = 'rfq';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'rfq_no',
        'issue_date',
        'due_date',
        'status',
        'awarded_bid_id',
        'awarded_purchase_order_id',
        'created_by',
        'memo',
        'request_id',
    ];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'due_date' => 'date',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(RfqLine::class, 'rfq_id')->orderBy('line_no');
    }

    public function bids(): HasMany
    {
        return $this->hasMany(RfqBid::class, 'rfq_id');
    }

    public function awardedPurchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'awarded_purchase_order_id');
    }
}
