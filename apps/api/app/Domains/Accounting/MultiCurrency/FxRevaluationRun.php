<?php

namespace App\Domains\Accounting\MultiCurrency;

use App\Exceptions\PostedSourceDocumentImmutableException;
use App\Models\PostingGroup;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FxRevaluationRun extends Model
{
    use HasUuids;

    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_POSTED = 'POSTED';

    public const STATUS_VOID = 'VOID';

    protected $table = 'fx_revaluation_runs';

    protected $fillable = [
        'tenant_id',
        'reference_no',
        'status',
        'as_of_date',
        'posting_date',
        'posting_group_id',
        'posted_at',
        'posted_by_user_id',
    ];

    protected $casts = [
        'as_of_date' => 'date',
        'posting_date' => 'date',
        'posted_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (FxRevaluationRun $run) {
            if ($run->getOriginal('status') !== self::STATUS_POSTED) {
                return;
            }
            $dirty = $run->getDirty();
            unset($dirty['updated_at']);
            if ($dirty !== []) {
                throw new PostedSourceDocumentImmutableException;
            }
        });

        static::deleting(function (FxRevaluationRun $run) {
            if ($run->getAttribute('status') === self::STATUS_POSTED) {
                throw new PostedSourceDocumentImmutableException('Posted FX revaluation runs cannot be deleted.');
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function postingGroup(): BelongsTo
    {
        return $this->belongsTo(PostingGroup::class);
    }

    public function postedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by_user_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(FxRevaluationLine::class, 'fx_revaluation_run_id');
    }
}
