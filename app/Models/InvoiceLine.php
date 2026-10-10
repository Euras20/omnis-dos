<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceLine extends Model
{
    use BelongsToCompany;

    protected $table = 'invoice_line';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'invoice_id',
        'line_no',
        'item_id',
        'sales_order_line_id',
        'description',
        'qty',
        'unit',
        'unit_price_minor',
        'net_minor',
        'vat_minor',
        'nhil_minor',
        'getfund_minor',
        'covid_minor',
        'gross_minor',
        'revenue_account_id',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:3',
            'unit_price_minor' => 'integer',
            'net_minor' => 'integer',
            'vat_minor' => 'integer',
            'nhil_minor' => 'integer',
            'getfund_minor' => 'integer',
            'covid_minor' => 'integer',
            'gross_minor' => 'integer',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }
}
