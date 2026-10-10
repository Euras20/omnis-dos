<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OpeningBatch extends Model
{
    use BelongsToCompany;

    protected $table = 'opening_batch';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'cutover_date' => 'date',
            'posted_at' => 'datetime',
        ];
    }

    public function rows(): HasMany
    {
        return $this->hasMany(OpeningRow::class, 'opening_batch_id');
    }
}
