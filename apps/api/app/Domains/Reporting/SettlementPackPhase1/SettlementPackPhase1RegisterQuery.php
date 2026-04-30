<?php

namespace App\Domains\Reporting\SettlementPackPhase1;

use App\Models\PostingGroup;
use Illuminate\Support\Facades\DB;

class SettlementPackPhase1RegisterQuery
{
    private const DEFAULT_PER_PAGE = 200;
    private const MAX_PER_PAGE = 1000;

    public function clampPerPage(?int $perPage): int
    {
        $pp = $perPage ?? self::DEFAULT_PER_PAGE;
        $pp = max(1, $pp);

        return min($pp, self::MAX_PER_PAGE);
    }

    /**
     * @return array{rows: list<array<string,mixed>>, total_rows: int}
     */
    public function allocationRegisterForProject(
        string $tenantId,
        string $projectId,
        string $from,
        string $to,
        string $order,
        int $page,
        int $perPage
    ): array {
        $base = DB::table('allocation_rows as ar')
            ->join('posting_groups as pg', 'pg.id', '=', 'ar.posting_group_id')
            ->where('ar.tenant_id', $tenantId)
            ->where('pg.tenant_id', $tenantId)
            ->where('ar.project_id', $projectId)
            ->whereDate('pg.posting_date', '>=', $from)
            ->whereDate('pg.posting_date', '<=', $to);
        PostingGroup::applyActiveOn($base, 'pg');

        $total = (clone $base)->count();

        $q = $base->select([
            'pg.posting_date',
            'pg.id as posting_group_id',
            'pg.source_type',
            'pg.source_id',
            'pg.crop_cycle_id',
            'ar.id as allocation_row_id',
            'ar.project_id',
            'ar.allocation_type',
            'ar.allocation_scope',
            'ar.party_id',
            DB::raw('COALESCE(ar.amount_base, ar.amount) as amount_effective'),
        ]);

        $this->applyAllocationOrder($q, $order);

        $rows = $q->offset(($page - 1) * $perPage)->limit($perPage)->get();

        $out = [];
        foreach ($rows as $r) {
            $pd = $r->posting_date;
            $out[] = [
                'posting_date' => is_object($pd) && method_exists($pd, 'format') ? $pd->format('Y-m-d') : (string) $pd,
                'posting_group_id' => (string) $r->posting_group_id,
                'source_type' => (string) $r->source_type,
                'source_id' => (string) $r->source_id,
                'crop_cycle_id' => $r->crop_cycle_id !== null ? (string) $r->crop_cycle_id : null,
                'project_id' => $r->project_id !== null ? (string) $r->project_id : null,
                'allocation_row_id' => (string) $r->allocation_row_id,
                'allocation_type' => (string) $r->allocation_type,
                'allocation_scope' => $r->allocation_scope !== null ? (string) $r->allocation_scope : null,
                'party_id' => $r->party_id !== null ? (string) $r->party_id : null,
                'amount' => number_format((float) ($r->amount_effective ?? 0), 2, '.', ''),
            ];
        }

        return ['rows' => $out, 'total_rows' => (int) $total];
    }

    /**
     * @param  list<string>  $projectIds
     * @return array{rows: list<array<string,mixed>>, total_rows: int}
     */
    public function allocationRegisterForCropCycle(
        string $tenantId,
        string $cropCycleId,
        array $projectIds,
        string $from,
        string $to,
        string $order,
        int $page,
        int $perPage
    ): array {
        $base = DB::table('allocation_rows as ar')
            ->join('posting_groups as pg', 'pg.id', '=', 'ar.posting_group_id')
            ->where('ar.tenant_id', $tenantId)
            ->where('pg.tenant_id', $tenantId)
            ->where('pg.crop_cycle_id', $cropCycleId)
            ->whereIn('ar.project_id', $projectIds)
            ->whereDate('pg.posting_date', '>=', $from)
            ->whereDate('pg.posting_date', '<=', $to);
        PostingGroup::applyActiveOn($base, 'pg');

        $total = (clone $base)->count();

        $q = $base->select([
            'pg.posting_date',
            'pg.id as posting_group_id',
            'pg.source_type',
            'pg.source_id',
            'pg.crop_cycle_id',
            'ar.id as allocation_row_id',
            'ar.project_id',
            'ar.allocation_type',
            'ar.allocation_scope',
            'ar.party_id',
            DB::raw('COALESCE(ar.amount_base, ar.amount) as amount_effective'),
        ]);

        $this->applyAllocationOrder($q, $order);

        $rows = $q->offset(($page - 1) * $perPage)->limit($perPage)->get();

        $out = [];
        foreach ($rows as $r) {
            $pd = $r->posting_date;
            $out[] = [
                'posting_date' => is_object($pd) && method_exists($pd, 'format') ? $pd->format('Y-m-d') : (string) $pd,
                'posting_group_id' => (string) $r->posting_group_id,
                'source_type' => (string) $r->source_type,
                'source_id' => (string) $r->source_id,
                'crop_cycle_id' => $r->crop_cycle_id !== null ? (string) $r->crop_cycle_id : null,
                'project_id' => $r->project_id !== null ? (string) $r->project_id : null,
                'allocation_row_id' => (string) $r->allocation_row_id,
                'allocation_type' => (string) $r->allocation_type,
                'allocation_scope' => $r->allocation_scope !== null ? (string) $r->allocation_scope : null,
                'party_id' => $r->party_id !== null ? (string) $r->party_id : null,
                'amount' => number_format((float) ($r->amount_effective ?? 0), 2, '.', ''),
            ];
        }

        return ['rows' => $out, 'total_rows' => (int) $total];
    }

    /**
     * @return array{rows: list<array<string,mixed>>, total_rows: int}
     */
    public function ledgerAuditRegisterForProject(
        string $tenantId,
        string $projectId,
        string $from,
        string $to,
        string $order,
        int $page,
        int $perPage
    ): array {
        $base = DB::table('ledger_entries as le')
            ->join('posting_groups as pg', 'pg.id', '=', 'le.posting_group_id')
            ->join('accounts as a', 'a.id', '=', 'le.account_id')
            ->join('allocation_rows as ar', function ($join) use ($tenantId, $projectId) {
                $join->on('ar.posting_group_id', '=', 'pg.id')
                    ->where('ar.tenant_id', '=', $tenantId)
                    ->where('ar.project_id', '=', $projectId);
            })
            ->where('le.tenant_id', $tenantId)
            ->where('pg.tenant_id', $tenantId)
            ->where('a.tenant_id', $tenantId)
            ->whereDate('pg.posting_date', '>=', $from)
            ->whereDate('pg.posting_date', '<=', $to);
        PostingGroup::applyActiveOn($base, 'pg');

        $total = (clone $base)->count();

        $q = $base->select([
            'pg.posting_date',
            'pg.id as posting_group_id',
            'pg.source_type',
            'pg.source_id',
            'pg.crop_cycle_id',
            'le.id as ledger_entry_id',
            'a.code as account_code',
            'a.name as account_name',
            'a.type as account_type',
            DB::raw('COALESCE(le.debit_amount_base, le.debit_amount) as debit_effective'),
            DB::raw('COALESCE(le.credit_amount_base, le.credit_amount) as credit_effective'),
            'ar.project_id',
            'ar.id as allocation_row_id',
            'ar.allocation_type',
            'ar.allocation_scope',
            'ar.party_id',
        ]);
        $this->applyLedgerOrder($q, $order);

        $rows = $q->offset(($page - 1) * $perPage)->limit($perPage)->get();

        $out = [];
        foreach ($rows as $r) {
            $pd = $r->posting_date;
            $out[] = [
                'posting_date' => is_object($pd) && method_exists($pd, 'format') ? $pd->format('Y-m-d') : (string) $pd,
                'posting_group_id' => (string) $r->posting_group_id,
                'source_type' => (string) $r->source_type,
                'source_id' => (string) $r->source_id,
                'crop_cycle_id' => $r->crop_cycle_id !== null ? (string) $r->crop_cycle_id : null,
                'project_id' => (string) $r->project_id,
                'allocation_row_id' => (string) $r->allocation_row_id,
                'allocation_type' => (string) $r->allocation_type,
                'allocation_scope' => $r->allocation_scope !== null ? (string) $r->allocation_scope : null,
                'party_id' => $r->party_id !== null ? (string) $r->party_id : null,
                'ledger_entry_id' => (string) $r->ledger_entry_id,
                'account_code' => (string) $r->account_code,
                'account_name' => (string) $r->account_name,
                'account_type' => (string) $r->account_type,
                'debit_amount' => number_format((float) ($r->debit_effective ?? 0), 2, '.', ''),
                'credit_amount' => number_format((float) ($r->credit_effective ?? 0), 2, '.', ''),
            ];
        }

        return ['rows' => $out, 'total_rows' => (int) $total];
    }

    /**
     * @param  list<string>  $projectIds
     * @return array{rows: list<array<string,mixed>>, total_rows: int}
     */
    public function ledgerAuditRegisterForCropCycle(
        string $tenantId,
        string $cropCycleId,
        array $projectIds,
        string $from,
        string $to,
        string $order,
        int $page,
        int $perPage
    ): array {
        $base = DB::table('ledger_entries as le')
            ->join('posting_groups as pg', 'pg.id', '=', 'le.posting_group_id')
            ->join('accounts as a', 'a.id', '=', 'le.account_id')
            ->join('allocation_rows as ar', function ($join) use ($tenantId, $projectIds) {
                $join->on('ar.posting_group_id', '=', 'pg.id')
                    ->where('ar.tenant_id', '=', $tenantId)
                    ->whereIn('ar.project_id', $projectIds);
            })
            ->where('le.tenant_id', $tenantId)
            ->where('pg.tenant_id', $tenantId)
            ->where('a.tenant_id', $tenantId)
            ->where('pg.crop_cycle_id', $cropCycleId)
            ->whereDate('pg.posting_date', '>=', $from)
            ->whereDate('pg.posting_date', '<=', $to);
        PostingGroup::applyActiveOn($base, 'pg');

        $total = (clone $base)->count();

        $q = $base->select([
            'pg.posting_date',
            'pg.id as posting_group_id',
            'pg.source_type',
            'pg.source_id',
            'pg.crop_cycle_id',
            'le.id as ledger_entry_id',
            'a.code as account_code',
            'a.name as account_name',
            'a.type as account_type',
            DB::raw('COALESCE(le.debit_amount_base, le.debit_amount) as debit_effective'),
            DB::raw('COALESCE(le.credit_amount_base, le.credit_amount) as credit_effective'),
            'ar.project_id',
            'ar.id as allocation_row_id',
            'ar.allocation_type',
            'ar.allocation_scope',
            'ar.party_id',
        ]);
        $this->applyLedgerOrder($q, $order);

        $rows = $q->offset(($page - 1) * $perPage)->limit($perPage)->get();

        $out = [];
        foreach ($rows as $r) {
            $pd = $r->posting_date;
            $out[] = [
                'posting_date' => is_object($pd) && method_exists($pd, 'format') ? $pd->format('Y-m-d') : (string) $pd,
                'posting_group_id' => (string) $r->posting_group_id,
                'source_type' => (string) $r->source_type,
                'source_id' => (string) $r->source_id,
                'crop_cycle_id' => $r->crop_cycle_id !== null ? (string) $r->crop_cycle_id : null,
                'project_id' => (string) $r->project_id,
                'allocation_row_id' => (string) $r->allocation_row_id,
                'allocation_type' => (string) $r->allocation_type,
                'allocation_scope' => $r->allocation_scope !== null ? (string) $r->allocation_scope : null,
                'party_id' => $r->party_id !== null ? (string) $r->party_id : null,
                'ledger_entry_id' => (string) $r->ledger_entry_id,
                'account_code' => (string) $r->account_code,
                'account_name' => (string) $r->account_name,
                'account_type' => (string) $r->account_type,
                'debit_amount' => number_format((float) ($r->debit_effective ?? 0), 2, '.', ''),
                'credit_amount' => number_format((float) ($r->credit_effective ?? 0), 2, '.', ''),
            ];
        }

        return ['rows' => $out, 'total_rows' => (int) $total];
    }

    private function applyAllocationOrder($q, string $order): void
    {
        if ($order === 'date_desc') {
            $q->orderByDesc('pg.posting_date')
                ->orderByDesc('pg.id')
                ->orderByDesc('ar.id');
        } else {
            $q->orderBy('pg.posting_date')
                ->orderBy('pg.id')
                ->orderBy('ar.id');
        }
    }

    private function applyLedgerOrder($q, string $order): void
    {
        if ($order === 'date_desc') {
            $q->orderByDesc('pg.posting_date')
                ->orderByDesc('pg.id')
                ->orderByDesc('ar.id')
                ->orderByDesc('le.id');
        } else {
            $q->orderBy('pg.posting_date')
                ->orderBy('pg.id')
                ->orderBy('ar.id')
                ->orderBy('le.id');
        }
    }
}

