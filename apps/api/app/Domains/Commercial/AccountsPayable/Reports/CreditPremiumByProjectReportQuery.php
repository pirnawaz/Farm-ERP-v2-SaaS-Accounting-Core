<?php

namespace App\Domains\Commercial\AccountsPayable\Reports;

use Illuminate\Support\Facades\DB;

final class CreditPremiumByProjectReportQuery
{
    /**
     * @return list<array<string, mixed>>
     */
    public function run(string $tenantId, array $filters): array
    {
        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;
        $partyId = $filters['party_id'] ?? null;
        $cropCycleId = $filters['crop_cycle_id'] ?? null;
        $projectId = $filters['project_id'] ?? null;

        $q = DB::table('allocation_rows as ar')
            ->join('posting_groups as pg', 'pg.id', '=', 'ar.posting_group_id')
            ->join('supplier_invoices as si', function ($join) {
                $join->on('si.id', '=', 'pg.source_id')
                    ->where('pg.source_type', '=', 'SUPPLIER_INVOICE');
            })
            ->join('parties as pa', 'pa.id', '=', 'si.party_id')
            ->leftJoin('projects as p', 'p.id', '=', 'ar.project_id')
            ->leftJoin('crop_cycles as cc', 'cc.id', '=', 'pg.crop_cycle_id')
            ->leftJoin('supplier_invoice_lines as sil', function ($join) {
                // allocation_rows.rule_snapshot->>'supplier_invoice_line_id' (uuid)
                $join->on('sil.id', '=', DB::raw("(ar.rule_snapshot->>'supplier_invoice_line_id')::uuid"));
            })
            ->where('ar.tenant_id', $tenantId)
            ->where('pg.tenant_id', $tenantId)
            ->where('ar.allocation_type', 'SUPPLIER_INVOICE_CREDIT_PREMIUM');

        // Only posted/paid invoices included (draft excluded).
        $q->whereIn('si.status', ['POSTED', 'PAID']);

        if ($from) {
            $q->where('pg.posting_date', '>=', $from);
        }
        if ($to) {
            $q->where('pg.posting_date', '<=', $to);
        }
        if ($partyId) {
            $q->where('si.party_id', $partyId);
        }
        if ($cropCycleId) {
            $q->where('pg.crop_cycle_id', $cropCycleId);
        }
        if ($projectId) {
            $q->where('ar.project_id', $projectId);
        }

        $rows = $q->selectRaw("
                pg.posting_date as posting_date,
                pg.crop_cycle_id as crop_cycle_id,
                cc.name as crop_cycle_name,
                ar.project_id as project_id,
                p.name as project_name,
                si.party_id as party_id,
                pa.name as supplier_name,
                si.id as supplier_invoice_id,
                sil.id as supplier_invoice_line_id,
                sil.line_no as line_no,
                COALESCE(sil.description, '') as description,
                SUM(ar.amount::numeric) as credit_premium_amount
            ")
            ->groupBy([
                'pg.posting_date',
                'pg.crop_cycle_id',
                'cc.name',
                'ar.project_id',
                'p.name',
                'si.party_id',
                'pa.name',
                'si.id',
                'sil.id',
                'sil.line_no',
                'sil.description',
            ])
            ->orderBy('pg.posting_date')
            ->orderBy('cc.name')
            ->orderBy('p.name')
            ->orderBy('pa.name')
            ->orderBy('si.id')
            ->orderBy('sil.line_no')
            ->get();

        return $rows->map(function ($r) {
            $prem = (float) $r->credit_premium_amount;
            return [
                'posting_date' => $r->posting_date,
                'crop_cycle_id' => $r->crop_cycle_id,
                'crop_cycle_name' => $r->crop_cycle_name,
                'project_id' => $r->project_id,
                'project_name' => $r->project_name,
                'supplier_name' => $r->supplier_name,
                'party_id' => $r->party_id,
                'supplier_invoice_id' => $r->supplier_invoice_id,
                'supplier_invoice_line_id' => $r->supplier_invoice_line_id,
                'line_no' => $r->line_no,
                'description' => $r->description,
                'credit_premium_amount' => number_format($prem, 2, '.', ''),
            ];
        })->values()->all();
    }
}

