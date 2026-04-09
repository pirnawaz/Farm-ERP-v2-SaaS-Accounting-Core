<?php

namespace App\Domains\Accounting\MultiCurrency;

use App\Exceptions\PostedSourceDocumentImmutableException;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FxRevaluationLine extends Model
{
    use HasUuids;

    public const SOURCE_SUPPLIER_AP = 'SUPPLIER_AP';

    public const SOURCE_LOAN_PAYABLE = 'LOAN_PAYABLE';

    protected $table = 'fx_revaluation_lines';

    protected $fillable = [
        'tenant_id',
        'fx_revaluation_run_id',
        'source_type',
        'source_id',
        'currency_code',
        'original_base_amount',
        'revalued_base_amount',
        'delta_amount',
    ];

    protected $casts = [
        'original_base_amount' => 'decimal:2',
        'revalued_base_amount' => 'decimal:2',
        'delta_amount' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (FxRevaluationLine $line) {
            if (! $line->fx_revaluation_run_id) {
                return;
            }
            $posted = FxRevaluationRun::query()
                ->where('id', $line->fx_revaluation_run_id)
                ->where('status', FxRevaluationRun::STATUS_POSTED)
                ->exists();
            if ($posted) {
                throw new PostedSourceDocumentImmutableException('Posted FX revaluation lines cannot be changed.');
            }
        });

        static::deleting(function (FxRevaluationLine $line) {
            $posted = FxRevaluationRun::query()
                ->where('id', $line->fx_revaluation_run_id)
                ->where('status', FxRevaluationRun::STATUS_POSTED)
                ->exists();
            if ($posted) {
                throw new PostedSourceDocumentImmutableException('Posted FX revaluation lines cannot be deleted.');
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(FxRevaluationRun::class, 'fx_revaluation_run_id');
    }
}
