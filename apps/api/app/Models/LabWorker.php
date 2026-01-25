<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class LabWorker extends Model
{
    use HasUuids;

    protected $table = 'lab_workers';

    protected $fillable = [
        'tenant_id',
        'worker_no',
        'name',
        'worker_type',
        'rate_basis',
        'default_rate',
        'phone',
        'is_active',
        'party_id',
    ];

    protected $casts = [
        'default_rate' => 'decimal:6',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'party_id');
    }

    public function workLogs(): HasMany
    {
        return $this->hasMany(LabWorkLog::class, 'worker_id');
    }

    public function balance(): HasOne
    {
        return $this->hasOne(LabWorkerBalance::class, 'worker_id');
    }

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }
}
