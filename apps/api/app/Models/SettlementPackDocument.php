<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SettlementPackDocument extends Model
{
    use HasUuids;

    public const STATUS_GENERATED = 'GENERATED';

    protected $fillable = [
        'tenant_id',
        'settlement_pack_id',
        'version',
        'status',
        'storage_key',
        'file_size_bytes',
        'sha256_hex',
        'content_type',
        'generated_at',
        'generated_by_user_id',
        'meta_json',
    ];

    protected $casts = [
        'generated_at' => 'datetime',
        'meta_json' => 'array',
        'created_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function settlementPack(): BelongsTo
    {
        return $this->belongsTo(SettlementPack::class);
    }

    public function generatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by_user_id');
    }
}
