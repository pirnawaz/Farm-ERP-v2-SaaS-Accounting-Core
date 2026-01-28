<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MachineWorkLog extends Model
{
    use HasUuids;

    protected $table = 'machine_work_logs';

    // Status constants
    public const STATUS_DRAFT = 'DRAFT';
    public const STATUS_POSTED = 'POSTED';
    public const STATUS_REVERSED = 'REVERSED';

    // Pool scope constants
    public const POOL_SCOPE_SHARED = 'SHARED';
    public const POOL_SCOPE_HARI_ONLY = 'HARI_ONLY';

    protected $fillable = [
        'tenant_id',
        'work_log_no',
        'status',
        'machine_id',
        'project_id',
        'crop_cycle_id',
        'pool_scope',
        'activity_id',
        'work_date',
        'meter_start',
        'meter_end',
        'usage_qty',
        'notes',
        'posting_date',
        'posted_at',
        'posting_group_id',
        'reversal_posting_group_id',
        'machinery_charge_id',
    ];

    protected $casts = [
        'work_date' => 'date',
        'posting_date' => 'date',
        'posted_at' => 'datetime',
        'meter_start' => 'decimal:2',
        'meter_end' => 'decimal:2',
        'usage_qty' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class, 'machine_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function cropCycle(): BelongsTo
    {
        return $this->belongsTo(CropCycle::class, 'crop_cycle_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(MachineWorkLogCostLine::class, 'machine_work_log_id');
    }

    public function postingGroup(): BelongsTo
    {
        return $this->belongsTo(PostingGroup::class, 'posting_group_id');
    }

    public function reversalPostingGroup(): BelongsTo
    {
        return $this->belongsTo(PostingGroup::class, 'reversal_posting_group_id');
    }

    public function machineryCharge(): BelongsTo
    {
        return $this->belongsTo(MachineryCharge::class);
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

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->pool_scope)) {
                $model->pool_scope = self::POOL_SCOPE_SHARED;
            }
        });
    }
}
