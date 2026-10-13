<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockCountLine extends Model
{
    use BelongsToCompany;

    protected $table = 'stock_count_line';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'stock_count_id',
        'line_no',
        'item_id',
        'lot',
        'book_qty',
        'counted_qty',
        'reason',
        'adjustment_movement_id',
    ];

    protected function casts(): array
    {
        return [
            'book_qty' => 'decimal:3',
            'counted_qty' => 'decimal:3',
        ];
    }

    public function stockCount(): BelongsTo
    {
        return $this->belongsTo(StockCount::class, 'stock_count_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }
}
