<?php

namespace App\Domains\Accounting\FixedAssets;

use App\Exceptions\PostedSourceDocumentImmutableException;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FixedAssetDepreciationLine extends Model
{
    use HasUuids;

    protected $table = 'fixed_asset_depreciation_lines';

    protected $fillable = [
        'tenant_id',
        'depreciation_run_id',
        'fixed_asset_id',
        'depreciation_amount',
        'opening_carrying_amount',
        'closing_carrying_amount',
        'depreciation_start',
        'depreciation_end',
    ];

    protected $casts = [
        'depreciation_amount' => 'decimal:2',
        'opening_carrying_amount' => 'decimal:2',
        'closing_carrying_amount' => 'decimal:2',
        'depreciation_start' => 'date',
        'depreciation_end' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (FixedAssetDepreciationLine $line) {
            if (! $line->depreciation_run_id) {
                return;
            }
            $posted = FixedAssetDepreciationRun::query()
                ->where('id', $line->depreciation_run_id)
                ->where('status', FixedAssetDepreciationRun::STATUS_POSTED)
                ->exists();
            if ($posted) {
                throw new PostedSourceDocumentImmutableException('Posted depreciation lines cannot be changed.');
            }
        });

        static::deleting(function (FixedAssetDepreciationLine $line) {
            $posted = FixedAssetDepreciationRun::query()
                ->where('id', $line->depreciation_run_id)
                ->where('status', FixedAssetDepreciationRun::STATUS_POSTED)
                ->exists();
            if ($posted) {
                throw new PostedSourceDocumentImmutableException('Posted depreciation lines cannot be deleted.');
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function depreciationRun(): BelongsTo
    {
        return $this->belongsTo(FixedAssetDepreciationRun::class, 'depreciation_run_id');
    }

    public function fixedAsset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id');
    }
}
