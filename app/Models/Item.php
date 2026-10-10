<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasRowVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Item extends Model
{
    use BelongsToCompany;
    use HasRowVersion;

    protected $table = 'item';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'sku',
        'name',
        'item_type',
        'category_code',
        'unit',
        'list_price_minor',
        'standard_cost_minor',
        'buy_price_minor',
        'weight_kg',
        'dimensions',
        'default_location_id',
        'default_supplier_id',
        'inventory_policy',
        'lot_controlled',
        'reorder_point',
        'safety_stock',
        'inventory_account_code',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'list_price_minor' => 'integer',
            'standard_cost_minor' => 'integer',
            'buy_price_minor' => 'integer',
            'weight_kg' => 'decimal:3',
            'dimensions' => 'array',
            'lot_controlled' => 'boolean',
            'reorder_point' => 'decimal:3',
            'safety_stock' => 'decimal:3',
            'is_active' => 'boolean',
            'row_version' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function defaultLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'default_location_id');
    }

    public function defaultSupplier(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'default_supplier_id');
    }
}
