<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class PostingGroup extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id',
        'crop_cycle_id',
        'source_type',
        'source_id',
        'posting_date',
        'idempotency_key',
        'reversal_of_posting_group_id',
        'correction_reason',
    ];

    protected $casts = [
        'posting_date' => 'date',
        'created_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function cropCycle(): BelongsTo
    {
        return $this->belongsTo(CropCycle::class);
    }

    public function allocationRows(): HasMany
    {
        return $this->hasMany(AllocationRow::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(PostingGroup::class, 'reversal_of_posting_group_id');
    }

    public function reversals(): HasMany
    {
        return $this->hasMany(PostingGroup::class, 'reversal_of_posting_group_id');
    }

    /**
     * Scope: active posting groups only.
     * A posting group is ACTIVE if it has not been reversed (no other posting group
     * has reversal_of_posting_group_id pointing to this one).
     * Use this for non-join queries (e.g. PostingGroup::query()->active()).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->whereDoesntHave('reversals');
    }

    /**
     * Apply active posting group filter to a query that has already joined posting_groups.
     * Use when the query joins posting_groups (e.g. allocation_rows join posting_groups)
     * so that only rows whose posting_group is not reversed are included.
     * Uses NOT EXISTS for efficiency (avoids whereIn subquery).
     * A posting group is reversed if there exists another posting_groups row (pg_rev)
     * where pg_rev.reversal_of_posting_group_id = {alias}.id; ActiveOn means no such row exists.
     * Call as PostingGroup::applyActiveOn($query, 'posting_groups') from services.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $alias Table name or alias of the joined posting_groups in the outer query (default 'posting_groups')
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public static function applyActiveOn($query, string $alias = 'posting_groups')
    {
        return $query->whereNotExists(function ($sub) use ($alias) {
            $sub->select(DB::raw(1))
                ->from('posting_groups as pg_rev')
                ->whereColumn('pg_rev.reversal_of_posting_group_id', $alias . '.id');
        });
    }

    /**
     * Scope: same as applyActiveOn, for use on PostingGroup query (e.g. PostingGroup::query()->activeOn('posting_groups')).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $alias
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActiveOn($query, string $alias = 'posting_groups')
    {
        return static::applyActiveOn($query, $alias);
    }
}
