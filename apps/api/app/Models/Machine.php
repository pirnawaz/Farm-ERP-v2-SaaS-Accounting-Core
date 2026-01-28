<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Machine extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'machine_type',
        'ownership_type',
        'status',
        'meter_unit',
        'opening_meter',
        'notes',
    ];

    protected $casts = [
        'opening_meter' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function workLogs(): HasMany
    {
        return $this->hasMany(MachineWorkLog::class, 'machine_id');
    }

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }
}
