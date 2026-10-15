<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Quotation extends Model
{
    use BelongsToCompany;

    protected $table = 'quotation';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'quotation_no',
        'customer_id',
        'quotation_date',
        'valid_until',
        'currency',
        'fx_rate',
        'discount_percent',
        'status',
        'net_minor',
        'tax_total_minor',
        'gross_minor',
        'tax_rule_version_id',
        'tax_snapshot',
        'memo',
        'converted_sales_order_id',
        'created_by',
        'request_id',
    ];

    protected function casts(): array
    {
        return [
            'quotation_date' => 'date',
            'valid_until' => 'date',
            'fx_rate' => 'decimal:8',
            'discount_percent' => 'decimal:2',
            'net_minor' => 'integer',
            'tax_total_minor' => 'integer',
            'gross_minor' => 'integer',
            'tax_snapshot' => 'array',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'customer_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(QuotationLine::class, 'quotation_id')->orderBy('line_no');
    }

    public function convertedSalesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'converted_sales_order_id');
    }
}
