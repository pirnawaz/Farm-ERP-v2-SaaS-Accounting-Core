<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShareRuleLine extends Model
{
    use HasUuids;

    protected $fillable = [
        'share_rule_id',
        'party_id',
        'percentage',
        'role',
    ];

    protected $casts = [
        'percentage' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function shareRule(): BelongsTo
    {
        return $this->belongsTo(ShareRule::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }
}
