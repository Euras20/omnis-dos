<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryNoteLine extends Model
{
    use BelongsToCompany;

    protected $table = 'delivery_note_line';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'delivery_note_id',
        'sales_order_line_id',
        'stock_assignment_id',
        'item_id',
        'location_id',
        'lot',
        'qty',
        'accepted_qty',
        'refused_qty',
        'line_no',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:3',
            'accepted_qty' => 'decimal:3',
            'refused_qty' => 'decimal:3',
            'line_no' => 'integer',
        ];
    }

    public function deliveryNote(): BelongsTo
    {
        return $this->belongsTo(DeliveryNote::class, 'delivery_note_id');
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(StockAssignment::class, 'stock_assignment_id');
    }
}
