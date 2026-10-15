<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShipmentEvent extends Model
{
    use BelongsToCompany;

    protected $table = 'shipment_event';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'shipment_id',
        'consignment_id',
        'event_at',
        'event_type',
        'location',
        'qty_affected',
        'source',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'event_at' => 'datetime',
            'qty_affected' => 'decimal:3',
        ];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class, 'shipment_id');
    }

    public function consignment(): BelongsTo
    {
        return $this->belongsTo(Consignment::class, 'consignment_id');
    }
}
