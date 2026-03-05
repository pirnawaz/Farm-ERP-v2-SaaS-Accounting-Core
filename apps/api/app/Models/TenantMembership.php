<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantMembership extends Model
{
    use HasUuids;

    protected $table = 'tenant_memberships';

    protected $fillable = [
        'identity_id',
        'tenant_id',
        'role',
        'is_enabled',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
    ];

    public function identity(): BelongsTo
    {
        return $this->belongsTo(Identity::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
