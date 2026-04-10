<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectPlanYield extends Model
{
    use HasUuids;

    protected $fillable = [
        'project_plan_id',
        'expected_quantity',
        'expected_unit_value',
    ];

    protected $casts = [
        'expected_quantity' => 'decimal:4',
        'expected_unit_value' => 'decimal:4',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function projectPlan(): BelongsTo
    {
        return $this->belongsTo(ProjectPlan::class);
    }
}
