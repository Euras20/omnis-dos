<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrder extends Model
{
    use BelongsToCompany;

    protected $table = 'purchase_order';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'po_no',
        'supplier_id',
        'order_date',
        'expected_date',
        'currency',
        'fx_rate',
        'status',
        'net_minor',
        'tax_total_minor',
        'gross_minor',
        'memo',
        'created_by',
        'approved_by',
        'approved_at',
        'request_id',
    ];

    protected function casts(): array
    {
        return [
            'order_date' => 'date',
            'expected_date' => 'date',
            'fx_rate' => 'decimal:8',
            'net_minor' => 'integer',
            'tax_total_minor' => 'integer',
            'gross_minor' => 'integer',
            'approved_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'supplier_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class, 'purchase_order_id')->orderBy('line_no');
    }

    public function goodsReceipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class, 'purchase_order_id');
    }
}
