<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectRule extends Model
{
    use HasUuids;

    public $timestamps = true;
    const UPDATED_AT = null; // Table doesn't have updated_at column

    protected $fillable = [
        'project_id',
        'profit_split_landlord_pct',
        'profit_split_hari_pct',
        'kamdari_pct',
        'kamdar_party_id',
        'kamdari_order',
        'pool_definition',
    ];

    protected $casts = [
        'profit_split_landlord_pct' => 'decimal:2',
        'profit_split_hari_pct' => 'decimal:2',
        'kamdari_pct' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function kamdarParty(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'kamdar_party_id');
    }
}
