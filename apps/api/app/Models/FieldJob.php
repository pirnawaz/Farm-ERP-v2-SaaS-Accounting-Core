<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FieldJob extends Model
{
    use HasUuids;

    protected $table = 'field_jobs';

    protected $fillable = [
        'tenant_id',
        'doc_no',
        'status',
        'job_date',
        'project_id',
        'crop_cycle_id',
        'production_unit_id',
        'land_parcel_id',
        'crop_activity_type_id',
        'notes',
        'posting_date',
        'posted_at',
        'posting_group_id',
        'reversal_posting_group_id',
        'reversed_at',
        'created_by',
    ];

    protected $casts = [
        'job_date' => 'date',
        'posting_date' => 'date',
        'posted_at' => 'datetime',
        'reversed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function cropCycle(): BelongsTo
    {
        return $this->belongsTo(CropCycle::class, 'crop_cycle_id');
    }

    public function productionUnit(): BelongsTo
    {
        return $this->belongsTo(ProductionUnit::class, 'production_unit_id');
    }

    public function landParcel(): BelongsTo
    {
        return $this->belongsTo(LandParcel::class, 'land_parcel_id');
    }

    public function cropActivityType(): BelongsTo
    {
        return $this->belongsTo(CropActivityType::class, 'crop_activity_type_id');
    }

    public function postingGroup(): BelongsTo
    {
        return $this->belongsTo(PostingGroup::class, 'posting_group_id');
    }

    public function reversalPostingGroup(): BelongsTo
    {
        return $this->belongsTo(PostingGroup::class, 'reversal_posting_group_id');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function inputs(): HasMany
    {
        return $this->hasMany(FieldJobInput::class, 'field_job_id');
    }

    public function labour(): HasMany
    {
        return $this->hasMany(FieldJobLabour::class, 'field_job_id');
    }

    public function machines(): HasMany
    {
        return $this->hasMany(FieldJobMachine::class, 'field_job_id');
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
}
