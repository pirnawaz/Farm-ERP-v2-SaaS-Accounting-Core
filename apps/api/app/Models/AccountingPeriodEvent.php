<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountingPeriodEvent extends Model
{
    use HasUuids;

    public const EVENT_CREATED = 'CREATED';
    public const EVENT_CLOSED = 'CLOSED';
    public const EVENT_REOPENED = 'REOPENED';

    public $timestamps = false;
    protected $fillable = [
        'tenant_id',
        'accounting_period_id',
        'event_type',
        'notes',
        'actor_id',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function accountingPeriod(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
