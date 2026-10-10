<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoodsReceiptLine extends Model
{
    use BelongsToCompany;

    protected $table = 'goods_receipt_line';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'goods_receipt_id',
        'purchase_order_line_id',
        'line_no',
        'item_id',
        'qty_received',
        'qty_certified',
        'qty_quarantine',
        'qty_rejected',
        'lot',
        'unit_cost_minor',
        'qc_status',
        'store_location_id',
        'stock_movement_id',
        'qc_note',
        'qc_by',
        'qc_at',
    ];

    protected function casts(): array
    {
        return [
            'qty_received' => 'decimal:3',
            'qty_certified' => 'decimal:3',
            'qty_quarantine' => 'decimal:3',
            'qty_rejected' => 'decimal:3',
            'unit_cost_minor' => 'integer',
            'qc_at' => 'datetime',
        ];
    }

    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class, 'goods_receipt_id');
    }

    public function purchaseOrderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class, 'purchase_order_line_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }
}
