<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Tenant extends Model
{
    use HasUuids;

    protected $fillable = [
        'name',
        'status',
        'currency_code',
        'locale',
        'timezone',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function farm(): HasOne
    {
        return $this->hasOne(Farm::class);
    }

    public function tenantModules(): HasMany
    {
        return $this->hasMany(TenantModule::class);
    }

    public function modules(): BelongsToMany
    {
        return $this->belongsToMany(Module::class, 'tenant_modules')
            ->withPivot('status', 'enabled_at', 'disabled_at', 'enabled_by_user_id');
    }

    /**
     * Whether the given module key is enabled for this tenant.
     * If no tenant_module row exists: core modules are enabled, non-core are disabled.
     */
    public function isModuleEnabled(string $key): bool
    {
        $module = Module::where('key', $key)->first();
        if (!$module) {
            return false;
        }
        $tm = TenantModule::where('tenant_id', $this->id)->where('module_id', $module->id)->first();
        if ($tm) {
            return $tm->status === 'ENABLED';
        }
        return $module->is_core;
    }
}
