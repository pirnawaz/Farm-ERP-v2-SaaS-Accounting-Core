<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MachineMaintenanceJob extends Model
{
    use HasUuids;

    protected $table = 'machine_maintenance_jobs';

    // Status constants
    public const STATUS_DRAFT = 'DRAFT';
    public const STATUS_POSTED = 'POSTED';
    public const STATUS_REVERSED = 'REVERSED';

    protected $fillable = [
        'tenant_id',
        'job_no',
        'status',
        'machine_id',
        'maintenance_type_id',
        'vendor_party_id',
        'job_date',
        'posting_date',
        'notes',
        'total_amount',
        'posting_group_id',
        'reversal_posting_group_id',
        'posted_at',
    ];

    protected $casts = [
        'job_date' => 'date',
        'posting_date' => 'date',
        'posted_at' => 'datetime',
        'total_amount' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    public function maintenanceType(): BelongsTo
    {
        return $this->belongsTo(MachineMaintenanceType::class, 'maintenance_type_id');
    }

    public function vendorParty(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'vendor_party_id');
    }

    public function postingGroup(): BelongsTo
    {
        return $this->belongsTo(PostingGroup::class, 'posting_group_id');
    }

    public function reversalPostingGroup(): BelongsTo
    {
        return $this->belongsTo(PostingGroup::class, 'reversal_posting_group_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(MachineMaintenanceJobLine::class, 'job_id');
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isPosted(): bool
    {
        return $this->status === self::STATUS_POSTED;
    }

    public function isReversed(): bool
    {
        return $this->status === self::STATUS_REVERSED;
    }

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }
}
