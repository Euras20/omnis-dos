<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RequisitionLine extends Model
{
    use BelongsToCompany;

    protected $table = 'requisition_line';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'requisition_id',
        'line_no',
        'item_id',
        'description',
        'qty',
        'unit',
        'estimated_unit_price_minor',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:3',
            'estimated_unit_price_minor' => 'integer',
        ];
    }

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(Requisition::class, 'requisition_id');
    }
}
