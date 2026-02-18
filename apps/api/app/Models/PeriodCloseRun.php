<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PeriodCloseRun extends Model
{
    use HasUuids;

    public const STATUS_COMPLETED = 'COMPLETED';

    protected $fillable = [
        'tenant_id',
        'crop_cycle_id',
        'posting_group_id',
        'status',
        'closed_at',
        'closed_by_user_id',
        'from_date',
        'to_date',
        'net_profit',
        'snapshot_json',
    ];

    protected $casts = [
        'from_date' => 'date',
        'to_date' => 'date',
        'net_profit' => 'decimal:2',
        'snapshot_json' => 'array',
        'closed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function cropCycle(): BelongsTo
    {
        return $this->belongsTo(CropCycle::class);
    }

    public function postingGroup(): BelongsTo
    {
        return $this->belongsTo(PostingGroup::class);
    }

    public function closedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }
}
