<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountingCorrection extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id',
        'original_posting_group_id',
        'reversal_posting_group_id',
        'corrected_posting_group_id',
        'reason',
        'correction_batch_run_at',
    ];

    protected $casts = [
        'correction_batch_run_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public const REASON_OPERATIONAL_PG_CONTAINS_PROFIT_DISTRIBUTION = 'OPERATIONAL_PG_CONTAINS_PROFIT_DISTRIBUTION';
    public const REASON_PARTY_CONTROL_CONSOLIDATION = 'PARTY_CONTROL_CONSOLIDATION';

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function originalPostingGroup(): BelongsTo
    {
        return $this->belongsTo(PostingGroup::class, 'original_posting_group_id');
    }

    public function reversalPostingGroup(): BelongsTo
    {
        return $this->belongsTo(PostingGroup::class, 'reversal_posting_group_id');
    }

    public function correctedPostingGroup(): BelongsTo
    {
        return $this->belongsTo(PostingGroup::class, 'corrected_posting_group_id');
    }
}
