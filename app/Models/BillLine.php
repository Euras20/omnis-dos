<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillLine extends Model
{
    use BelongsToCompany;

    protected $table = 'bill_line';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'bill_id',
        'line_no',
        'purchase_order_line_id',
        'item_id',
        'description',
        'qty',
        'unit',
        'unit_price_minor',
        'net_minor',
        'tax_minor',
        'gross_minor',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:3',
            'unit_price_minor' => 'integer',
            'net_minor' => 'integer',
            'tax_minor' => 'integer',
            'gross_minor' => 'integer',
        ];
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class, 'bill_id');
    }

    public function purchaseOrderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class, 'purchase_order_line_id');
    }
}
