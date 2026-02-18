<?php

namespace App\Domains\Reporting;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Composable query helpers for read-only reporting.
 * Assumes the query joins posting_groups (table name "posting_groups").
 * Use for tenant isolation, project/crop_cycle filters, and consistent reversal exclusion.
 */
final class ReportingQuery
{
    /**
     * Restrict to a tenant. Use when query joins posting_groups.
     */
    public static function applyTenant(Builder $query, string $tenantId): void
    {
        $query->where('posting_groups.tenant_id', $tenantId);
    }

    /**
     * Optional: restrict to postings allocated to a specific project.
     * posting_groups has no project_id column; project linkage is via allocation_rows.
     * Uses EXISTS so only posting groups with at least one allocation_row for this project are included.
     * This correctly excludes other projects in the same crop cycle.
     */
    public static function applyProjectFilter(Builder $query, ?string $projectId): void
    {
        if ($projectId === null || $projectId === '') {
            return;
        }
        $query->whereExists(function (Builder $sub) use ($projectId) {
            $sub->select(DB::raw(1))
                ->from('allocation_rows as ar')
                ->whereColumn('ar.posting_group_id', 'posting_groups.id')
                ->where('ar.project_id', '=', $projectId);
        });
    }

    /**
     * Optional: restrict to postings for a crop cycle.
     */
    public static function applyCropCycleFilter(Builder $query, ?string $cropCycleId): void
    {
        if ($cropCycleId === null || $cropCycleId === '') {
            return;
        }
        $query->where('posting_groups.crop_cycle_id', $cropCycleId);
    }

    /**
     * Exclude reversal posting groups and originals that have been reversed.
     * - Keep only rows where posting_groups.reversal_of_posting_group_id IS NULL (exclude "reversal" PGs).
     * - Exclude originals that have been reversed: NOT EXISTS (pg2 where pg2.reversal_of_posting_group_id = posting_groups.id).
     */
    public static function applyExcludeReversals(Builder $query): void
    {
        $query->whereNull('posting_groups.reversal_of_posting_group_id')
            ->whereNotExists(function (Builder $sub) {
                $sub->select(DB::raw(1))
                    ->from('posting_groups as pg2')
                    ->whereColumn('pg2.tenant_id', 'posting_groups.tenant_id')
                    ->whereColumn('pg2.reversal_of_posting_group_id', 'posting_groups.id');
            });
    }
}
