<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasRowVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CorrectiveAction extends Model
{
    use BelongsToCompany;
    use HasRowVersion;

    protected $table = 'corrective_action';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'row_version' => 'integer',
        ];
    }

    public function nonconformance(): BelongsTo
    {
        return $this->belongsTo(Nonconformance::class, 'nonconformance_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(AppUser::class, 'owner_id');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(AppUser::class, 'verifier_id');
    }
}
