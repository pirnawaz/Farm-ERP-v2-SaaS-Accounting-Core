<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantModule extends Model
{
    use HasUuids;

    protected $table = 'tenant_modules';

    protected $fillable = [
        'tenant_id',
        'module_id',
        'status',
        'enabled_at',
        'disabled_at',
        'enabled_by_user_id',
    ];

    protected $casts = [
        'enabled_at' => 'datetime',
        'disabled_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class);
    }

    public function enabledByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enabled_by_user_id');
    }
}
