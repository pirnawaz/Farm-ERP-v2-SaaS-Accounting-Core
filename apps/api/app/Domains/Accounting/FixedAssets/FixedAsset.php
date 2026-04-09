<?php

namespace App\Domains\Accounting\FixedAssets;

use App\Models\PostingGroup;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class FixedAsset extends Model
{
    use HasUuids;

    protected $table = 'fixed_assets';

    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_DISPOSED = 'DISPOSED';

    public const STATUS_RETIRED = 'RETIRED';

    public const DEPRECIATION_STRAIGHT_LINE = 'STRAIGHT_LINE';

    protected $fillable = [
        'tenant_id',
        'project_id',
        'asset_code',
        'name',
        'category',
        'acquisition_date',
        'in_service_date',
        'status',
        'currency_code',
        'acquisition_cost',
        'residual_value',
        'useful_life_months',
        'depreciation_method',
        'notes',
        'created_by',
        'activation_posting_group_id',
        'activated_at',
        'activated_by_user_id',
    ];

    protected $casts = [
        'acquisition_date' => 'date',
        'in_service_date' => 'date',
        'acquisition_cost' => 'decimal:2',
        'residual_value' => 'decimal:2',
        'useful_life_months' => 'integer',
        'activated_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (FixedAsset $asset) {
            if ($asset->getOriginal('status') !== self::STATUS_ACTIVE) {
                return;
            }
            $frozen = [
                'tenant_id', 'project_id', 'asset_code', 'acquisition_date', 'in_service_date',
                'currency_code', 'acquisition_cost', 'residual_value', 'useful_life_months',
                'depreciation_method', 'activation_posting_group_id', 'activated_at', 'activated_by_user_id',
            ];
            foreach ($frozen as $key) {
                if ($asset->isDirty($key)) {
                    throw ValidationException::withMessages([
                        $key => ['This field cannot be changed after the asset is activated.'],
                    ]);
                }
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function activationPostingGroup(): BelongsTo
    {
        return $this->belongsTo(PostingGroup::class, 'activation_posting_group_id');
    }

    public function activatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by_user_id');
    }

    public function books(): HasMany
    {
        return $this->hasMany(FixedAssetBook::class, 'fixed_asset_id');
    }

    public function depreciationLines(): HasMany
    {
        return $this->hasMany(FixedAssetDepreciationLine::class, 'fixed_asset_id');
    }

    public function disposals(): HasMany
    {
        return $this->hasMany(FixedAssetDisposal::class, 'fixed_asset_id');
    }
}
