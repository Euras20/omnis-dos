<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class GoodsReturn extends Model
{
    use BelongsToCompany;

    protected $table = 'goods_return';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'return_no',
        'kind',
        'status',
        'sales_order_id',
        'sales_order_line_id',
        'delivery_note_id',
        'delivery_note_line_id',
        'party_id',
        'item_id',
        'location_id',
        'restock_location_id',
        'lot',
        'qty',
        'unit_cost_minor',
        'reason',
        'authorization',
        'credit_note_id',
        'journal_id',
        'return_date',
        'row_version',
        'created_by',
        'inspected_by',
        'request_id',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:3',
            'unit_cost_minor' => 'integer',
            'return_date' => 'date',
            'row_version' => 'integer',
        ];
    }
}
