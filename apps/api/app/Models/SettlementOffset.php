<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SettlementOffset extends Model
{
    use HasUuids;

    public $timestamps = false;
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = [
        'tenant_id',
        'settlement_id',
        'party_id',
        'posting_group_id',
        'posting_date',
        'offset_amount',
    ];

    protected $casts = [
        'posting_date' => 'date',
        'offset_amount' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(Settlement::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function postingGroup(): BelongsTo
    {
        return $this->belongsTo(PostingGroup::class);
    }
}
