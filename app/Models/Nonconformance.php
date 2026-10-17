<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasRowVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Nonconformance extends Model
{
    use BelongsToCompany;
    use HasRowVersion;

    protected $table = 'nonconformance';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:3',
            'due_date' => 'date',
            'occurred_at' => 'date',
            'row_version' => 'integer',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(AppUser::class, 'owner_id');
    }

    public function correctiveAction(): HasOne
    {
        return $this->hasOne(CorrectiveAction::class, 'nonconformance_id');
    }
}
