<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CropActivity extends Model
{
    use HasUuids;

    protected $table = 'crop_activities';

    protected $fillable = [
        'tenant_id',
        'doc_no',
        'activity_type_id',
        'activity_date',
        'crop_cycle_id',
        'project_id',
        'land_parcel_id',
        'notes',
        'status',
        'posting_date',
        'posting_group_id',
        'posted_at',
        'reversed_at',
        'created_by',
    ];

    protected $casts = [
        'activity_date' => 'date',
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

    public function type(): BelongsTo
    {
        return $this->belongsTo(CropActivityType::class, 'activity_type_id');
    }

    public function cropCycle(): BelongsTo
    {
        return $this->belongsTo(CropCycle::class, 'crop_cycle_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function landParcel(): BelongsTo
    {
        return $this->belongsTo(LandParcel::class, 'land_parcel_id');
    }

    public function postingGroup(): BelongsTo
    {
        return $this->belongsTo(PostingGroup::class, 'posting_group_id');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function inputs(): HasMany
    {
        return $this->hasMany(CropActivityInput::class, 'activity_id');
    }

    public function labour(): HasMany
    {
        return $this->hasMany(CropActivityLabour::class, 'activity_id');
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
}
