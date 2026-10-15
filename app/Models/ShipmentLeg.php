<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShipmentLeg extends Model
{
    use BelongsToCompany;

    protected $table = 'shipment_leg';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'consignment_id',
        'seq',
        'from_location',
        'to_location',
        'carrier_id',
        'vessel',
        'planned_dep',
        'planned_arr',
        'actual_dep',
        'actual_arr',
    ];

    protected function casts(): array
    {
        return [
            'seq' => 'integer',
            'planned_dep' => 'date',
            'planned_arr' => 'date',
            'actual_dep' => 'date',
            'actual_arr' => 'date',
        ];
    }

    public function consignment(): BelongsTo
    {
        return $this->belongsTo(Consignment::class, 'consignment_id');
    }

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'carrier_id');
    }
}
