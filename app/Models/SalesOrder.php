<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesOrder extends Model
{
    use BelongsToCompany;

    protected $table = 'sales_order';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'order_no',
        'customer_id',
        'quotation_id',
        'customer_po_ref',
        'order_type_id',
        'order_date',
        'required_date',
        'currency',
        'fx_rate',
        'status',
        'net_minor',
        'tax_total_minor',
        'gross_minor',
        'tax_rule_version_id',
        'tax_snapshot',
        'memo',
        'deliver_to',
        'created_by',
        'confirmed_by',
        'confirmed_at',
        'request_id',
    ];

    protected function casts(): array
    {
        return [
            'order_date' => 'date',
            'required_date' => 'date',
            'fx_rate' => 'decimal:8',
            'net_minor' => 'integer',
            'tax_total_minor' => 'integer',
            'gross_minor' => 'integer',
            'tax_snapshot' => 'array',
            'confirmed_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'customer_id');
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class, 'quotation_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SalesOrderLine::class, 'sales_order_id')->orderBy('line_no');
    }
}
