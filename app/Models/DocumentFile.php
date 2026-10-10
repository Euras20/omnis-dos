<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class DocumentFile extends Model
{
    use BelongsToCompany;

    protected $table = 'document_file';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'uploaded_at' => 'datetime',
        ];
    }
}
