<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OverheadAllocationLine extends Model
{
    use HasUuids;

    protected $table = 'overhead_allocation_lines';

    protected $fillable = [
        'tenant_id',
        'overhead_allocation_header_id',
        'project_id',
        'amount',
        'percent',
        'basis_value',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'percent' => 'decimal:4',
        'basis_value' => 'decimal:4',
    ];

    public function header(): BelongsTo
    {
        return $this->belongsTo(OverheadAllocationHeader::class, 'overhead_allocation_header_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
