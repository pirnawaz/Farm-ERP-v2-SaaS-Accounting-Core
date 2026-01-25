<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Advance extends Model
{
    use HasUuids;

    public $timestamps = true;
    const UPDATED_AT = null; // Table doesn't have updated_at column

    protected $fillable = [
        'tenant_id',
        'party_id',
        'type',
        'direction',
        'amount',
        'posting_date',
        'method',
        'status',
        'posting_group_id',
        'posted_at',
        'project_id',
        'crop_cycle_id',
        'notes',
        'idempotency_key',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'posting_date' => 'date',
        'posted_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function postingGroup(): BelongsTo
    {
        return $this->belongsTo(PostingGroup::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function cropCycle(): BelongsTo
    {
        return $this->belongsTo(CropCycle::class);
    }

    /**
     * Scope to filter advances by tenant.
     */
    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * Scope to filter draft advances.
     */
    public function scopeDraft($query)
    {
        return $query->where('status', 'DRAFT');
    }

    /**
     * Scope to filter posted advances.
     */
    public function scopePosted($query)
    {
        return $query->where('status', 'POSTED');
    }

    /**
     * Check if advance can be updated (only DRAFT status).
     */
    public function canBeUpdated(): bool
    {
        return $this->status === 'DRAFT';
    }

    /**
     * Check if advance can be deleted (only DRAFT status).
     */
    public function canBeDeleted(): bool
    {
        return $this->status === 'DRAFT';
    }
}
