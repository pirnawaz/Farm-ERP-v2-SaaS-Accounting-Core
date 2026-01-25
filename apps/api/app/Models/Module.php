<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Module extends Model
{
    protected $fillable = [
        'key',
        'name',
        'description',
        'is_core',
        'sort_order',
    ];

    protected $casts = [
        'is_core' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'tenant_modules')
            ->withPivot('status', 'enabled_at', 'disabled_at', 'enabled_by_user_id');
    }
}
