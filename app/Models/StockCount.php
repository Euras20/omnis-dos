<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockCount extends Model
{
    use BelongsToCompany;

    protected $table = 'stock_count';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'count_no',
        'location_id',
        'count_date',
        'status',
        'counted_by',
        'counted_at',
        'approved_by',
        'approved_at',
        'memo',
        'created_by',
        'request_id',
    ];

    protected function casts(): array
    {
        return [
            'count_date' => 'date',
            'counted_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockCountLine::class, 'stock_count_id')->orderBy('line_no');
    }
}
