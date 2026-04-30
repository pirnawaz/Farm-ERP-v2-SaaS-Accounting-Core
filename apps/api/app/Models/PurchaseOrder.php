<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrder extends Model
{
    use HasUuids;

    protected $table = 'purchase_orders';

    public const STATUS_DRAFT = 'DRAFT';
    public const STATUS_APPROVED = 'APPROVED';
    public const STATUS_PARTIALLY_RECEIVED = 'PARTIALLY_RECEIVED';
    public const STATUS_RECEIVED = 'RECEIVED';
    public const STATUS_CANCELLED = 'CANCELLED';

    protected $fillable = [
        'tenant_id',
        'supplier_id',
        'po_no',
        'po_date',
        'status',
        'notes',
        'approved_at',
        'approved_by',
        'created_by',
    ];

    protected $casts = [
        'po_date' => 'date',
        'approved_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class, 'purchase_order_id');
    }

    public function canBeUpdated(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }
}

