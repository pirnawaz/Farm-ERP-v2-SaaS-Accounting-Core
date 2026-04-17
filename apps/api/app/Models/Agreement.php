<?php

namespace App\Models;

use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Agreement extends Model
{
    use HasUuids;

    public const TYPE_LAND_LEASE = 'LAND_LEASE';

    public const TYPE_MACHINE_USAGE = 'MACHINE_USAGE';

    public const TYPE_LABOUR = 'LABOUR';

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_INACTIVE = 'INACTIVE';

    protected $table = 'agreements';

    protected $fillable = [
        'tenant_id',
        'agreement_type',
        'project_id',
        'crop_cycle_id',
        'party_id',
        'machine_id',
        'worker_id',
        'terms',
        'effective_from',
        'effective_to',
        'priority',
        'status',
    ];

    protected $casts = [
        'terms' => 'array',
        'effective_from' => 'date',
        'effective_to' => 'date',
        'priority' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
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

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(LabWorker::class, 'worker_id');
    }

    public function agreementAllocations(): HasMany
    {
        return $this->hasMany(AgreementAllocation::class, 'agreement_id');
    }

    /**
     * Whether the agreement is ACTIVE and the given calendar date falls within
     * [effective_from, effective_to] (inclusive; open-ended when effective_to is null).
     */
    public function isActive(DateTimeInterface|string $date): bool
    {
        if ($this->status !== self::STATUS_ACTIVE) {
            return false;
        }

        $d = Carbon::parse($date)->startOfDay();
        $from = Carbon::parse($this->effective_from)->startOfDay();
        if ($d->lt($from)) {
            return false;
        }

        if ($this->effective_to !== null) {
            $to = Carbon::parse($this->effective_to)->startOfDay();
            if ($d->gt($to)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether this agreement's project scope matches the given project.
     * Null project_id means "not restricted to a single project" (tenant-wide within other filters).
     */
    public function appliesToProject(string $projectId): bool
    {
        return $this->project_id === null || $this->project_id === $projectId;
    }

    /**
     * Whether this agreement applies to the harvest's tenant, scope (project / crop cycle), and date.
     */
    public function appliesToHarvest(Harvest $harvest): bool
    {
        if ($this->tenant_id !== $harvest->tenant_id) {
            return false;
        }

        if (! $this->appliesToProject($harvest->project_id)) {
            return false;
        }

        if ($this->crop_cycle_id !== null && $this->crop_cycle_id !== $harvest->crop_cycle_id) {
            return false;
        }

        return $this->isActive($harvest->harvest_date);
    }

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeActiveStatus($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }
}
