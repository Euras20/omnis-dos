<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasRowVersion;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Staff or portal user (principal).
 *
 * Thin Eloquent model. Auth middleware and PermissionChecker still accept
 * the lightweight value object shape via fromRow / toPrincipal helpers so
 * existing Shared call sites stay stable.
 *
 * Full Identity admin surface lands in Step 14.5.
 */
class AppUser extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasRowVersion;

    protected $table = 'app_user';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'email',
        'display_name',
        'external_subject',
        'user_type',
        'party_id',
        'employee_id',
        'is_active',
        'phone',
        'job_title',
        'signature_path',
        'created_by',
        'last_seen_at',
        'deactivated_at',
        'deactivation_reason',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_seen_at' => 'datetime',
            'deactivated_at' => 'datetime',
            'row_version' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(
            Role::class,
            'user_role',
            'user_id',
            'role_id',
        )->withTimestamps();
    }

    public function permissionOverrides(): HasMany
    {
        return $this->hasMany(UserPermissionOverride::class, 'user_id');
    }

    public function locationScopes(): HasMany
    {
        return $this->hasMany(UserLocationScope::class, 'user_id');
    }

    public function isPortal(): bool
    {
        return $this->user_type === 'portal' || $this->party_id !== null;
    }

    public function getAuthIdentifier(): string
    {
        return (string) $this->id;
    }

    /**
     * Hydrate from a raw DB row (used by Authenticate middleware).
     * Does not run global scopes — caller already filtered.
     *
     * @param  object|array<string, mixed>  $row
     */
    public static function fromRow(object|array $row): self
    {
        $data = is_array($row) ? $row : (array) $row;
        $model = new self;
        $model->forceFill([
            'id' => (string) ($data['id'] ?? ''),
            'company_id' => (string) ($data['company_id'] ?? ''),
            'email' => (string) ($data['email'] ?? ''),
            'display_name' => (string) ($data['display_name'] ?? ''),
            'external_subject' => isset($data['external_subject']) ? (string) $data['external_subject'] : null,
            'user_type' => (string) ($data['user_type'] ?? 'staff'),
            'party_id' => isset($data['party_id']) ? (string) $data['party_id'] : null,
            'employee_id' => isset($data['employee_id']) ? (string) $data['employee_id'] : null,
            'is_active' => (bool) ($data['is_active'] ?? true),
            'phone' => isset($data['phone']) ? (string) $data['phone'] : null,
            'job_title' => isset($data['job_title']) ? (string) $data['job_title'] : null,
            'signature_path' => isset($data['signature_path']) ? (string) $data['signature_path'] : null,
        ]);
        $model->exists = true;
        $model->syncOriginal();

        return $model;
    }

    /**
     * @return array{id: string, company_id: string, email: string, is_active: bool, external_subject: ?string, user_type: string, party_id: ?string, display_name: ?string}
     */
    public function toPrincipalArray(): array
    {
        return [
            'id' => (string) $this->id,
            'company_id' => (string) $this->company_id,
            'email' => (string) $this->email,
            'is_active' => (bool) $this->is_active,
            'external_subject' => $this->external_subject !== null ? (string) $this->external_subject : null,
            'user_type' => (string) $this->user_type,
            'party_id' => $this->party_id !== null ? (string) $this->party_id : null,
            'display_name' => $this->display_name !== null ? (string) $this->display_name : null,
        ];
    }
}
