<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuotationLine extends Model
{
    use BelongsToCompany;

    protected $table = 'quotation_line';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'quotation_id',
        'line_no',
        'item_id',
        'description',
        'qty',
        'unit',
        'unit_price_minor',
        'net_minor',
        'tax_minor',
        'gross_minor',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:3',
            'unit_price_minor' => 'integer',
            'net_minor' => 'integer',
            'tax_minor' => 'integer',
            'gross_minor' => 'integer',
        ];
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class, 'quotation_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }
}
