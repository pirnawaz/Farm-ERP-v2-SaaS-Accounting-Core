<?php

namespace App\Domains\Governance\SettlementPack;

use App\Models\PostingGroup;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Read-only, posted data only: active (non-reversed) posting groups with posting_date <= as_of.
 * Used for settlement pack register lines and builder metrics.
 */
class SettlementPackRegisterQuery
{
    /**
     * Allocation-level rows (one row per allocation_row) — backward-compatible register_rows shape.
     *
     * @return list<array{posting_group_id: string, posting_date: string, source_type: string, source_id: string, allocation_row_id: string, allocation_type: string, allocation_scope: string|null, amount: string, party_id: string|null}>
     */
    public function allocationRegisterRows(string $tenantId, string $projectId, string $asOfDate): array
    {
        $this->assertTenantOwnsProject($tenantId, $projectId);

        $rows = DB::table('allocation_rows as ar')
            ->join('posting_groups as pg', 'pg.id', '=', 'ar.posting_group_id')
            ->where('ar.tenant_id', $tenantId)
            ->where('ar.project_id', $projectId)
            ->where('pg.tenant_id', $tenantId)
            ->whereDate('pg.posting_date', '<=', $asOfDate);
        PostingGroup::applyActiveOn($rows, 'pg');

        $rows = $rows
            ->orderBy('pg.posting_date')
            ->orderBy('pg.id')
            ->orderBy('ar.id')
            ->select([
                'pg.id as posting_group_id',
                'pg.posting_date',
                'pg.source_type',
                'pg.source_id',
                'ar.id as allocation_row_id',
                'ar.allocation_type',
                'ar.allocation_scope',
                'ar.amount',
                'ar.party_id',
            ])
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $pd = $row->posting_date;
            $out[] = [
                'posting_group_id' => $row->posting_group_id,
                'posting_date' => is_object($pd) && method_exists($pd, 'format') ? $pd->format('Y-m-d') : (string) $pd,
                'source_type' => (string) $row->source_type,
                'source_id' => (string) $row->source_id,
                'allocation_row_id' => $row->allocation_row_id,
                'allocation_type' => (string) $row->allocation_type,
                'allocation_scope' => $row->allocation_scope !== null ? (string) $row->allocation_scope : null,
                'amount' => $this->money($row->amount),
                'party_id' => $row->party_id !== null ? (string) $row->party_id : null,
            ];
        }

        return $out;
    }

    /**
     * Line-by-line register: ledger entry × allocation (project-scoped), for audit trail.
     * Ordered deterministically.
     *
     * @return list<array{posting_date: string, source_type: string, source_id: string, posting_group_id: string, ledger_entry_id: string, account_code: string, account_name: string, account_type: string, debit_amount: string, credit_amount: string, project_id: string, allocation_row_id: string, allocation_type: string, allocation_scope: string|null, party_id: string|null}>
     */
    public function registerLines(string $tenantId, string $projectId, string $asOfDate): array
    {
        $this->assertTenantOwnsProject($tenantId, $projectId);

        $q = DB::table('ledger_entries as le')
            ->join('posting_groups as pg', 'pg.id', '=', 'le.posting_group_id')
            ->join('accounts as a', 'a.id', '=', 'le.account_id')
            ->join('allocation_rows as ar', function ($join) use ($projectId, $tenantId) {
                $join->on('ar.posting_group_id', '=', 'pg.id')
                    ->where('ar.project_id', '=', $projectId)
                    ->where('ar.tenant_id', '=', $tenantId);
            })
            ->where('le.tenant_id', $tenantId)
            ->where('pg.tenant_id', $tenantId)
            ->whereDate('pg.posting_date', '<=', $asOfDate);
        PostingGroup::applyActiveOn($q, 'pg');

        $rows = $q
            ->orderBy('pg.posting_date')
            ->orderBy('pg.id')
            ->orderBy('ar.id')
            ->orderBy('le.id')
            ->select([
                'pg.posting_date',
                'pg.source_type',
                'pg.source_id',
                'pg.id as posting_group_id',
                'le.id as ledger_entry_id',
                'a.code as account_code',
                'a.name as account_name',
                'a.type as account_type',
                'le.debit_amount',
                'le.credit_amount',
                'ar.project_id',
                'ar.id as allocation_row_id',
                'ar.allocation_type',
                'ar.allocation_scope',
                'ar.party_id',
            ])
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $pd = $row->posting_date;
            $out[] = [
                'posting_date' => is_object($pd) && method_exists($pd, 'format') ? $pd->format('Y-m-d') : (string) $pd,
                'source_type' => (string) $row->source_type,
                'source_id' => (string) $row->source_id,
                'posting_group_id' => (string) $row->posting_group_id,
                'ledger_entry_id' => (string) $row->ledger_entry_id,
                'account_code' => (string) $row->account_code,
                'account_name' => (string) $row->account_name,
                'account_type' => (string) $row->account_type,
                'debit_amount' => $this->money($row->debit_amount),
                'credit_amount' => $this->money($row->credit_amount),
                'project_id' => (string) $row->project_id,
                'allocation_row_id' => (string) $row->allocation_row_id,
                'allocation_type' => (string) $row->allocation_type,
                'allocation_scope' => $row->allocation_scope !== null ? (string) $row->allocation_scope : null,
                'party_id' => $row->party_id !== null ? (string) $row->party_id : null,
            ];
        }

        return $out;
    }

    /**
     * One row per ledger entry for posting groups that touch this project (no allocation join duplication).
     *
     * @return list<array{ledger_entry_id: string, account_type: string, debit_amount: string, credit_amount: string}>
     */
    public function distinctLedgerEntriesForPostingGroups(string $tenantId, string $projectId, string $asOfDate): array
    {
        $this->assertTenantOwnsProject($tenantId, $projectId);

        $pgIdsQuery = DB::table('allocation_rows as ar')
            ->join('posting_groups as pg', 'pg.id', '=', 'ar.posting_group_id')
            ->where('ar.tenant_id', $tenantId)
            ->where('ar.project_id', $projectId)
            ->where('pg.tenant_id', $tenantId)
            ->whereDate('pg.posting_date', '<=', $asOfDate);
        PostingGroup::applyActiveOn($pgIdsQuery, 'pg');
        $pgIds = $pgIdsQuery->distinct()->pluck('ar.posting_group_id');
        if ($pgIds->isEmpty()) {
            return [];
        }

        $rows = DB::table('ledger_entries as le')
            ->join('accounts as a', 'a.id', '=', 'le.account_id')
            ->where('le.tenant_id', $tenantId)
            ->whereIn('le.posting_group_id', $pgIds)
            ->orderBy('le.posting_group_id')
            ->orderBy('le.id')
            ->select([
                'le.id as ledger_entry_id',
                'a.type as account_type',
                'le.debit_amount',
                'le.credit_amount',
            ])
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'ledger_entry_id' => (string) $row->ledger_entry_id,
                'account_type' => (string) $row->account_type,
                'debit_amount' => $this->money($row->debit_amount),
                'credit_amount' => $this->money($row->credit_amount),
            ];
        }

        return $out;
    }

    /**
     * Sum of allocation amounts by type (posted, project-scoped, as-of).
     *
     * @return array<string, string>
     */
    public function sumByAllocationType(string $tenantId, string $projectId, string $asOfDate): array
    {
        $this->assertTenantOwnsProject($tenantId, $projectId);

        $rows = DB::table('allocation_rows as ar')
            ->join('posting_groups as pg', 'pg.id', '=', 'ar.posting_group_id')
            ->where('ar.tenant_id', $tenantId)
            ->where('ar.project_id', $projectId)
            ->where('pg.tenant_id', $tenantId)
            ->whereDate('pg.posting_date', '<=', $asOfDate);
        PostingGroup::applyActiveOn($rows, 'pg');

        $rows = $rows
            ->groupBy('ar.allocation_type')
            ->selectRaw('ar.allocation_type, COALESCE(SUM(ar.amount), 0) as total')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->allocation_type] = $this->money($row->total);
        }
        ksort($out);

        return $out;
    }

    private function assertTenantOwnsProject(string $tenantId, string $projectId): void
    {
        $ok = Project::where('id', $projectId)->where('tenant_id', $tenantId)->exists();
        if (! $ok) {
            throw new InvalidArgumentException('Project not found for tenant.');
        }
    }

    private function money(mixed $value): string
    {
        if ($value === null) {
            return '0.00';
        }

        return number_format((float) $value, 2, '.', '');
    }
}
