<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LabWorkerBalance extends Model
{
    use HasUuids;

    protected $table = 'lab_worker_balances';

    protected $fillable = [
        'tenant_id',
        'worker_id',
        'payable_balance',
    ];

    protected $casts = [
        'payable_balance' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(LabWorker::class, 'worker_id');
    }

    /**
     * Get or create a balance row for a worker. Used when posting work logs.
     */
    public static function getOrCreate(string $tenantId, string $workerId): self
    {
        $row = self::where('tenant_id', $tenantId)
            ->where('worker_id', $workerId)
            ->first();

        if ($row) {
            return $row;
        }

        return self::create([
            'tenant_id' => $tenantId,
            'worker_id' => $workerId,
            'payable_balance' => 0,
        ]);
    }

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }
}
