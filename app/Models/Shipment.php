<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasRowVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Inbound or domestic shipment (SH-…).
 * One shipment can cover many purchase orders via shipment_purchase_order.
 * Jobs are not modelled here.
 */
class Shipment extends Model
{
    use BelongsToCompany;
    use HasRowVersion;

    protected $table = 'shipment';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'shipment_no',
        'kind',
        'transport',
        'mode',
        'booking',
        'incoterm',
        'shipper_id',
        'consignee',
        'carrier_id',
        'forwarder_id',
        'insurance',
        'status',
        'owner',
        'note',
        'row_version',
        'created_by',
        'request_id',
    ];

    protected function casts(): array
    {
        return [
            'row_version' => 'integer',
        ];
    }

    public function consignments(): HasMany
    {
        return $this->hasMany(Consignment::class, 'shipment_id');
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(ShipmentPurchaseOrder::class, 'shipment_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ShipmentEvent::class, 'shipment_id');
    }

    public function customs(): HasMany
    {
        return $this->hasMany(CustomsDeclaration::class, 'shipment_id');
    }

    public function landedCosts(): HasMany
    {
        return $this->hasMany(LandedCost::class, 'shipment_id');
    }

    public function shipper(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'shipper_id');
    }

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'carrier_id');
    }
}
