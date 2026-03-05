<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Identity extends Model
{
    use HasUuids;

    protected $table = 'identities';

    protected $fillable = [
        'email',
        'password_hash',
        'is_enabled',
        'is_platform_admin',
        'token_version',
        'last_login_at',
    ];

    protected $hidden = [
        'password_hash',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'is_platform_admin' => 'boolean',
        'last_login_at' => 'datetime',
    ];

    public function tenantMemberships(): HasMany
    {
        return $this->hasMany(TenantMembership::class);
    }

    public function tenantUserProfiles(): HasMany
    {
        return $this->hasMany(TenantUserProfile::class);
    }

    /** Enabled memberships only. */
    public function enabledMemberships(): HasMany
    {
        return $this->hasMany(TenantMembership::class)->where('is_enabled', true);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'identity_id');
    }
}
