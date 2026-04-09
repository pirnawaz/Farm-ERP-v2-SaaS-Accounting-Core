<?php

namespace App\Domains\Accounting\FixedAssets;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FixedAssetBook extends Model
{
    use HasUuids;

    protected $table = 'fixed_asset_books';

    public const BOOK_PRIMARY = 'PRIMARY';

    protected $fillable = [
        'tenant_id',
        'fixed_asset_id',
        'book_type',
        'asset_cost',
        'accumulated_depreciation',
        'carrying_amount',
        'last_depreciation_date',
    ];

    protected $casts = [
        'asset_cost' => 'decimal:2',
        'accumulated_depreciation' => 'decimal:2',
        'carrying_amount' => 'decimal:2',
        'last_depreciation_date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function fixedAsset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id');
    }
}
