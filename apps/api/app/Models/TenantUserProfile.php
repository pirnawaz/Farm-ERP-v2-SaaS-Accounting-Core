<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantUserProfile extends Model
{
    use HasUuids;

    protected $table = 'tenant_user_profiles';

    protected $fillable = [
        'identity_id',
        'tenant_id',
        'display_name',
        'phone',
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
