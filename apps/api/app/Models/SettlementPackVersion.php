<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SettlementPackVersion extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id',
        'settlement_pack_id',
        'version_no',
        'snapshot_json',
        'generated_by_user_id',
        'generated_at',
        'pdf_path',
    ];

    protected $casts = [
        'snapshot_json' => 'array',
        'generated_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
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
