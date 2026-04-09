<?php

namespace App\Domains\Accounting\FixedAssets;

use App\Exceptions\PostedSourceDocumentImmutableException;
use App\Models\PostingGroup;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FixedAssetDisposal extends Model
{
    use HasUuids;

    protected $table = 'fixed_asset_disposals';

    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_POSTED = 'POSTED';

    protected $fillable = [
        'tenant_id',
        'fixed_asset_id',
        'disposal_date',
        'proceeds_amount',
        'proceeds_account',
        'status',
        'posting_date',
        'posted_at',
        'posted_by_user_id',
        'posting_group_id',
        'carrying_amount_at_post',
        'gain_amount',
        'loss_amount',
        'notes',
    ];

    protected $casts = [
        'disposal_date' => 'date',
        'proceeds_amount' => 'decimal:2',
        'carrying_amount_at_post' => 'decimal:2',
        'gain_amount' => 'decimal:2',
        'loss_amount' => 'decimal:2',
        'posting_date' => 'date',
        'posted_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (FixedAssetDisposal $disposal) {
            if ($disposal->getOriginal('status') !== self::STATUS_POSTED) {
                return;
            }
            $dirty = $disposal->getDirty();
            unset($dirty['updated_at']);
            if ($dirty !== []) {
                throw new PostedSourceDocumentImmutableException;
            }
        });

        static::deleting(function (FixedAssetDisposal $disposal) {
            if ($disposal->getAttribute('status') === self::STATUS_POSTED) {
                throw new PostedSourceDocumentImmutableException('Posted disposals cannot be deleted.');
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function fixedAsset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id');
    }

    public function postedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by_user_id');
    }

    public function postingGroup(): BelongsTo
    {
        return $this->belongsTo(PostingGroup::class, 'posting_group_id');
    }
}
