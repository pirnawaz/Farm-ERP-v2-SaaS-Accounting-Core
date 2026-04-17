<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OverheadAllocationHeader extends Model
{
    use HasUuids;

    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_POSTED = 'POSTED';

    protected $table = 'overhead_allocation_headers';

    protected $fillable = [
        'tenant_id',
        'cost_center_id',
        'source_posting_group_id',
        'allocation_date',
        'method',
        'notes',
        'status',
        'posting_group_id',
    ];

    protected $casts = [
        'allocation_date' => 'date',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(OverheadAllocationLine::class, 'overhead_allocation_header_id');
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    public function sourcePostingGroup(): BelongsTo
    {
        return $this->belongsTo(PostingGroup::class, 'source_posting_group_id');
    }

    public function postingGroup(): BelongsTo
    {
        return $this->belongsTo(PostingGroup::class, 'posting_group_id');
    }
}
