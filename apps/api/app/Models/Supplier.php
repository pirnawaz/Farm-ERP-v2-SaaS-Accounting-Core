<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id',
        'name',
        'status',
        'party_id',
        'phone',
        'email',
        'address',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function bills(): HasMany
    {
        return $this->hasMany(SupplierBill::class);
    }
}

