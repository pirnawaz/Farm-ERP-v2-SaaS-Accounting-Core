<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CropCycle extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id',
        'name',
        'start_date',
        'end_date',
        'status',
        'closed_at',
        'closed_by_user_id',
        'close_note',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'status' => 'string',
        'closed_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function landAllocations(): HasMany
    {
        return $this->hasMany(LandAllocation::class);
    }

    public function operationalTransactions(): HasMany
    {
        return $this->hasMany(OperationalTransaction::class);
    }

    public function postingGroups(): HasMany
    {
        return $this->hasMany(PostingGroup::class);
    }

    public function closedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }

    public function periodCloseRun(): HasOne
    {
        return $this->hasOne(PeriodCloseRun::class);
    }
}
