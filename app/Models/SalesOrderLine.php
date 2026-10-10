<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesOrderLine extends Model
{
    use BelongsToCompany;

    protected $table = 'sales_order_line';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'sales_order_id',
        'line_no',
        'item_id',
        'quotation_line_id',
        'description',
        'qty',
        'unit',
        'unit_price_minor',
        'net_minor',
        'tax_minor',
        'gross_minor',
        'qty_delivered',
        'qty_invoiced',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:3',
            'unit_price_minor' => 'integer',
            'net_minor' => 'integer',
            'tax_minor' => 'integer',
            'gross_minor' => 'integer',
            'qty_delivered' => 'decimal:3',
            'qty_invoiced' => 'decimal:3',
        ];
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }
}
