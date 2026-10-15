<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasRowVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Multi-stage delivery note (DN-…) for the dispatch board.
 * Stock is issued and COGS posted only when status becomes delivered.
 */
class DeliveryNote extends Model
{
    use BelongsToCompany;
    use HasRowVersion;

    protected $table = 'delivery_note';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'delivery_no',
        'sales_order_id',
        'status',
        'planned_date',
        'revised_date',
        'route',
        'packages',
        'weight_kg',
        'volume_m3',
        'vehicle',
        'carrier',
        'driver',
        'picked_by',
        'packed_by',
        'dispatch_at',
        'delivered_at',
        'attempt',
        'receiver',
        'pod_reference',
        'note',
        'fail_reason',
        'row_version',
        'created_by',
        'request_id',
    ];

    protected function casts(): array
    {
        return [
            'planned_date' => 'date',
            'revised_date' => 'date',
            'weight_kg' => 'decimal:2',
            'volume_m3' => 'decimal:3',
            'dispatch_at' => 'datetime',
            'delivered_at' => 'datetime',
            'attempt' => 'integer',
            'row_version' => 'integer',
        ];
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(DeliveryNoteLine::class, 'delivery_note_id');
    }
}
