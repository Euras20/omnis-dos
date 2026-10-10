<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Reservation of on-hand stock against a sales order line (or free assignment).
 * Status lifecycle: pending → assigned | rejected; assigned → released | delivered.
 */
class StockAssignment extends Model
{
    use BelongsToCompany;

    protected $table = 'stock_assignment';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'item_id',
        'location_id',
        'lot',
        'qty',
        'status',
        'sales_order_line_id',
        'requested_by',
        'decided_by',
        'decided_at',
        'decision_note',
        'memo',
        'created_by',
        'request_id',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:3',
            'decided_at' => 'datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }
}
