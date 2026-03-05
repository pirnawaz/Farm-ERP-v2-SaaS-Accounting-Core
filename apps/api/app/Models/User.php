<?php

namespace App\Models;

use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends Model implements AuthenticatableContract
{
    use HasUuids;

    /** The users table has created_at but not updated_at. */
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::saving(function (User $user) {
            if ($user->role === 'platform_admin') {
                if ($user->tenant_id !== null) {
                    throw new \LogicException('Platform admin must have tenant_id null.');
                }
            }
            if ($user->tenant_id === null) {
                if ($user->role !== 'platform_admin') {
                    throw new \LogicException('User without tenant must have role platform_admin.');
                }
            }
        });
    }

    protected $fillable = [
        'tenant_id',
        'name',
        'email',
        'password',
        'role',
        'is_enabled',
        'token_version',
        'must_change_password',
        'last_password_change_at',
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'is_enabled' => 'boolean',
        'must_change_password' => 'boolean',
        'last_password_change_at' => 'datetime',
    ];

    /** Tenant users: must have tenant_id set; roles are tenant_admin, accountant, operator. */
    public function scopeTenantUsers($query)
    {
        return $query->whereNotNull('tenant_id');
    }

    /** Platform admins: tenant_id null, role platform_admin. */
    public function scopePlatformAdmins($query)
    {
        return $query->whereNull('tenant_id')->where('role', 'platform_admin');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function createdTransactions(): HasMany
    {
        return $this->hasMany(OperationalTransaction::class, 'created_by');
    }

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): string
    {
        return $this->getKey();
    }

    public function getAuthPassword(): string
    {
        return $this->password ?? '';
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getRememberToken(): ?string
    {
        return null;
    }

    public function setRememberToken($value): void
    {
    }

    public function getRememberTokenName(): ?string
    {
        return null;
    }
}
