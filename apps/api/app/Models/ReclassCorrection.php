<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReclassCorrection extends Model
{
    use HasUuids;

    protected $table = 'reclass_corrections';

    protected $fillable = [
        'tenant_id',
        'operational_transaction_id',
        'posting_group_id',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function operationalTransaction(): BelongsTo
    {
        return $this->belongsTo(OperationalTransaction::class);
    }

    public function postingGroup(): BelongsTo
    {
        return $this->belongsTo(PostingGroup::class);
    }
}
