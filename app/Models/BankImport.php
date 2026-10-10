<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankImport extends Model
{
    use BelongsToCompany;

    protected $table = 'bank_import';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'column_mapping' => 'array',
            'line_count' => 'integer',
            'skipped_count' => 'integer',
            'imported_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BankStatementLine::class, 'bank_import_id');
    }
}
