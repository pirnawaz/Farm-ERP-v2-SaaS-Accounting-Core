<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Settlement extends Model
{
    use HasUuids;

    public $timestamps = true;
    const UPDATED_AT = null; // Table doesn't have updated_at column

    protected $fillable = [
        'tenant_id',
        'project_id',
        'posting_group_id',
        'pool_revenue',
        'shared_costs',
        'pool_profit',
        'kamdari_amount',
        'landlord_share',
        'hari_share',
        'hari_only_deductions',
    ];

    protected $casts = [
        'pool_revenue' => 'decimal:2',
        'shared_costs' => 'decimal:2',
        'pool_profit' => 'decimal:2',
        'kamdari_amount' => 'decimal:2',
        'landlord_share' => 'decimal:2',
        'hari_share' => 'decimal:2',
        'hari_only_deductions' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function postingGroup(): BelongsTo
    {
        return $this->belongsTo(PostingGroup::class);
    }

    public function offsets(): HasMany
    {
        return $this->hasMany(SettlementOffset::class);
    }
}
