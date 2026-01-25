<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvIssueLine extends Model
{
    use HasUuids;

    protected $table = 'inv_issue_lines';

    protected $fillable = [
        'tenant_id',
        'issue_id',
        'item_id',
        'qty',
        'unit_cost_snapshot',
        'line_total',
    ];

    protected $casts = [
        'qty' => 'decimal:6',
        'unit_cost_snapshot' => 'decimal:6',
        'line_total' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(InvIssue::class, 'issue_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InvItem::class, 'item_id');
    }
}
