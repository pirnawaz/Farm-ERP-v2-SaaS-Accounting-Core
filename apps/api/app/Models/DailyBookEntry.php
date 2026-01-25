<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyBookEntry extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id',
        'project_id',
        'type',
        'status',
        'event_date',
        'description',
        'gross_amount',
        'currency_code',
    ];

    protected $casts = [
        'event_date' => 'date',
        'gross_amount' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
