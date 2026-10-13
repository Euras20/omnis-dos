<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RfqLine extends Model
{
    use BelongsToCompany;

    protected $table = 'rfq_line';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'rfq_id',
        'line_no',
        'item_id',
        'description',
        'qty',
        'unit',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:3',
        ];
    }

    public function rfq(): BelongsTo
    {
        return $this->belongsTo(Rfq::class, 'rfq_id');
    }
}
