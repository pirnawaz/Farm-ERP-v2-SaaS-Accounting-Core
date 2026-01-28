<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LabWorkLog extends Model
{
    use HasUuids;

    protected $table = 'lab_work_logs';

    protected $fillable = [
        'tenant_id',
        'doc_no',
        'worker_id',
        'work_date',
        'crop_cycle_id',
        'project_id',
        'activity_id',
        'machine_id',
        'rate_basis',
        'units',
        'rate',
        'amount',
        'notes',
        'status',
        'posting_date',
        'posting_group_id',
        'created_by',
    ];

    protected $casts = [
        'work_date' => 'date',
        'posting_date' => 'date',
        'units' => 'decimal:6',
        'rate' => 'decimal:6',
        'amount' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(LabWorker::class, 'worker_id');
    }

    public function cropCycle(): BelongsTo
    {
        return $this->belongsTo(CropCycle::class, 'crop_cycle_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class, 'machine_id');
    }

    public function postingGroup(): BelongsTo
    {
        return $this->belongsTo(PostingGroup::class, 'posting_group_id');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isDraft(): bool
    {
        return $this->status === 'DRAFT';
    }

    public function isPosted(): bool
    {
        return $this->status === 'POSTED';
    }

    public function isReversed(): bool
    {
        return $this->status === 'REVERSED';
    }

    public function canBeUpdated(): bool
    {
        return $this->isDraft();
    }

    public function canBePosted(): bool
    {
        return $this->isDraft();
    }

    public function canBeReversed(): bool
    {
        return $this->isPosted();
    }

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }
}
