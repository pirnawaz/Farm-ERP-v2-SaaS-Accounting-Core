<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperationalTransaction extends Model
{
    use HasUuids;

    protected $table = 'operational_transactions';

    protected $fillable = [
        'tenant_id',
        'project_id',
        'crop_cycle_id',
        'type',
        'status',
        'transaction_date',
        'amount',
        'classification',
        'posting_group_id',
        'created_by',
    ];

    protected $casts = [
        'transaction_date' => 'date',
        'amount' => 'decimal:2',
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

    public function cropCycle(): BelongsTo
    {
        return $this->belongsTo(CropCycle::class);
    }

    public function postingGroup(): BelongsTo
    {
        return $this->belongsTo(PostingGroup::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
