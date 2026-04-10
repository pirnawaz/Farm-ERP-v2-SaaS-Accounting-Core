<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectPlanCost extends Model
{
    use HasUuids;

    public const COST_TYPE_INPUT = 'INPUT';

    public const COST_TYPE_LABOUR = 'LABOUR';

    public const COST_TYPE_MACHINERY = 'MACHINERY';

    protected $fillable = [
        'project_plan_id',
        'cost_type',
        'expected_quantity',
        'expected_cost',
    ];

    protected $casts = [
        'expected_quantity' => 'decimal:4',
        'expected_cost' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function projectPlan(): BelongsTo
    {
        return $this->belongsTo(ProjectPlan::class);
    }
}
