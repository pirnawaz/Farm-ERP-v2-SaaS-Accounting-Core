<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductionUnit extends Model
{
    use HasUuids;

    protected $table = 'production_units';

    public const TYPE_SEASONAL = 'SEASONAL';
    public const TYPE_LONG_CYCLE = 'LONG_CYCLE';
    public const STATUS_ACTIVE = 'ACTIVE';
    public const STATUS_CLOSED = 'CLOSED';
    public const CATEGORY_ORCHARD = 'ORCHARD';
    public const CATEGORY_LIVESTOCK = 'LIVESTOCK';

    protected $fillable = [
        'tenant_id',
        'name',
        'type',
        'start_date',
        'end_date',
        'status',
        'notes',
        'category',
        'orchard_crop',
        'planting_year',
        'area_acres',
        'tree_count',
        'livestock_type',
        'herd_start_count',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'planting_year' => 'integer',
        'area_acres' => 'decimal:4',
        'tree_count' => 'integer',
        'herd_start_count' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function livestockEvents(): HasMany
    {
        return $this->hasMany(LivestockEvent::class, 'production_unit_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function cropActivities(): HasMany
    {
        return $this->hasMany(CropActivity::class, 'production_unit_id');
    }

    public function labWorkLogs(): HasMany
    {
        return $this->hasMany(LabWorkLog::class, 'production_unit_id');
    }

    public function invIssues(): HasMany
    {
        return $this->hasMany(InvIssue::class, 'production_unit_id');
    }

    public function harvests(): HasMany
    {
        return $this->hasMany(Harvest::class, 'production_unit_id');
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class, 'production_unit_id');
    }
}
