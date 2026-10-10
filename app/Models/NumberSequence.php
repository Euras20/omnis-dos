<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Server-side document number sequences.
 * Allocation is atomic (SELECT … FOR UPDATE) inside the posting transaction.
 */
class NumberSequence extends Model
{
    use BelongsToCompany;

    protected $table = 'number_sequence';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'doc_type',
        'fiscal_year',
        'next_value',
        'prefix',
        'pad_length',
    ];

    protected function casts(): array
    {
        return [
            'fiscal_year' => 'integer',
            'next_value' => 'integer',
            'pad_length' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }
}
