<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasRowVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One physical unit of cargo inside a shipment (container / AWB / etc.). */
class Consignment extends Model
{
    use BelongsToCompany;
    use HasRowVersion;

    protected $table = 'consignment';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'shipment_id',
        'consignment_no',
        'purchase_order_line_id',
        'item_id',
        'qty',
        'container',
        'seal',
        'bl',
        'packages',
        'weight_kg',
        'volume_m3',
        'status',
        'eta',
        'goods_receipt_id',
        'row_version',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:3',
            'weight_kg' => 'decimal:2',
            'volume_m3' => 'decimal:3',
            'packages' => 'integer',
            'eta' => 'date',
            'row_version' => 'integer',
        ];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class, 'shipment_id');
    }

    public function purchaseOrderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class, 'purchase_order_line_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    public function legs(): HasMany
    {
        return $this->hasMany(ShipmentLeg::class, 'consignment_id')->orderBy('seq');
    }
}
