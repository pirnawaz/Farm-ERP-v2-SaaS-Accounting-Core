<?php

namespace App\Domains\Accounting\FixedAssets;

use App\Exceptions\PostedSourceDocumentImmutableException;
use App\Models\PostingGroup;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FixedAssetDepreciationRun extends Model
{
    use HasUuids;

    protected $table = 'fixed_asset_depreciation_runs';

    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_POSTED = 'POSTED';

    public const STATUS_VOID = 'VOID';

    protected $fillable = [
        'tenant_id',
        'reference_no',
        'status',
        'period_start',
        'period_end',
        'posting_date',
        'posted_at',
        'posted_by_user_id',
        'posting_group_id',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'posting_date' => 'date',
        'posted_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (FixedAssetDepreciationRun $run) {
            if ($run->getOriginal('status') !== self::STATUS_POSTED) {
                return;
            }
            $dirty = $run->getDirty();
            unset($dirty['updated_at']);
            if ($dirty !== []) {
                throw new PostedSourceDocumentImmutableException;
            }
        });

        static::deleting(function (FixedAssetDepreciationRun $run) {
            if ($run->getAttribute('status') === self::STATUS_POSTED) {
                throw new PostedSourceDocumentImmutableException('Posted depreciation runs cannot be deleted.');
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function postedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by_user_id');
    }

    public function postingGroup(): BelongsTo
    {
        return $this->belongsTo(PostingGroup::class, 'posting_group_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(FixedAssetDepreciationLine::class, 'depreciation_run_id');
    }
}
