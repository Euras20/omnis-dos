<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GoodsReceipt extends Model
{
    use BelongsToCompany;

    protected $table = 'goods_receipt';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'grn_no',
        'purchase_order_id',
        'location_id',
        'receipt_date',
        'status',
        'journal_id',
        'memo',
        'created_by',
        'request_id',
    ];

    protected function casts(): array
    {
        return [
            'receipt_date' => 'date',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class, 'journal_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(GoodsReceiptLine::class, 'goods_receipt_id')->orderBy('line_no');
    }
}
