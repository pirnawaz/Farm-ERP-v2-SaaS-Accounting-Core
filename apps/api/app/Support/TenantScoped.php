<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * Enforces tenant_id on Eloquent builders (Phase 1H.5). Prefer over raw find($id) on tenant-owned rows.
 */
final class TenantScoped
{
    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function for(Builder $query, string $tenantId): Builder
    {
        $table = $query->getModel()->getTable();

        return $query->where($table.'.tenant_id', $tenantId);
    }
}
