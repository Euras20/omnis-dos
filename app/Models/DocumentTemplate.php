<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasRowVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentTemplate extends Model
{
    use BelongsToCompany;
    use HasRowVersion;

    protected $table = 'document_template';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'doc_type',
        'status',
        'version',
        'settings',
        'published_by',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'settings' => 'array',
            'published_at' => 'datetime',
            'row_version' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(AppUser::class, 'published_by');
    }
}
