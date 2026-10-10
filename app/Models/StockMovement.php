<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only inventory ledger row.
 * Never update or delete; reverse via opposite-signed movement.
 */
class StockMovement extends Model
{
    use BelongsToCompany;

    protected $table = 'stock_movement';

    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'company_id',
        'movement_type',
        'item_id',
        'location_id',
        'lot',
        'qty',
        'unit_cost_minor',
        'movement_date',
        'source_type',
        'source_id',
        'sales_order_line_id',
        'goods_receipt_line_id',
        'stock_assignment_id',
        'journal_id',
        'memo',
        'created_by',
        'request_id',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:3',
            'unit_cost_minor' => 'integer',
            'movement_date' => 'date',
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

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class, 'journal_id');
    }
}
