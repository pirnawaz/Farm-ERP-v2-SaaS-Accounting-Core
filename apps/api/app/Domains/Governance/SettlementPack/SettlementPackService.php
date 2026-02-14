<?php

namespace App\Domains\Governance\SettlementPack;

use App\Models\Project;
use App\Models\SettlementPack;
use App\Models\AllocationRow;
use Illuminate\Support\Facades\DB;

/**
 * Generates and retrieves Settlement Packs (read-only snapshot of project transaction register).
 * No ledger or posting group mutations.
 */
class SettlementPackService
{
    /**
     * Generate or return existing settlement pack for project + register_version (idempotent).
     *
     * @return array{pack: SettlementPack, summary: array, register_row_count: int}
     */
    public function generateOrReturn(
        string $projectId,
        string $tenantId,
        ?string $generatedByUserId,
        string $registerVersion = 'default'
    ): array {
        $project = Project::where('id', $projectId)->where('tenant_id', $tenantId)->firstOrFail();

        $existing = SettlementPack::where('tenant_id', $tenantId)
            ->where('project_id', $projectId)
            ->where('register_version', $registerVersion)
            ->first();

        if ($existing) {
            $summary = $existing->summary_json ?? [];
            $registerRowCount = $this->countRegisterRows($projectId, $tenantId);
            return [
                'pack' => $existing,
                'summary' => $summary,
                'register_row_count' => $registerRowCount,
            ];
        }

        $registerRows = $this->buildRegisterRows($projectId, $tenantId);
        $summary = $this->buildSummary($registerRows);

        $pack = SettlementPack::create([
            'tenant_id' => $tenantId,
            'project_id' => $projectId,
            'generated_by_user_id' => $generatedByUserId,
            'generated_at' => now(),
            'status' => SettlementPack::STATUS_DRAFT,
            'summary_json' => $summary,
            'register_version' => $registerVersion,
        ]);

        return [
            'pack' => $pack,
            'summary' => $summary,
            'register_row_count' => count($registerRows),
        ];
    }

    /**
     * Get pack by id (tenant-scoped) with full transaction register rows.
     *
     * @return array{pack: SettlementPack, summary: array, register_rows: array}
     */
    public function getWithRegister(string $packId, string $tenantId): array
    {
        $pack = SettlementPack::where('id', $packId)
            ->where('tenant_id', $tenantId)
            ->with(['project', 'generatedByUser'])
            ->firstOrFail();

        $registerRows = $this->buildRegisterRows($pack->project_id, $tenantId);
        $summary = $pack->summary_json ?? $this->buildSummary($registerRows);

        return [
            'pack' => $pack,
            'summary' => $summary,
            'register_rows' => $registerRows,
        ];
    }

    /**
     * Build transaction register rows for the project (allocation rows + posting group info).
     *
     * @return list<array{posting_group_id: string, posting_date: string, source_type: string, source_id: string, allocation_row_id: string, allocation_type: string, amount: string, party_id: string|null}>
     */
    public function buildRegisterRows(string $projectId, string $tenantId): array
    {
        $rows = DB::table('allocation_rows as ar')
            ->join('posting_groups as pg', 'pg.id', '=', 'ar.posting_group_id')
            ->where('ar.tenant_id', $tenantId)
            ->where('ar.project_id', $projectId)
            ->select([
                'pg.id as posting_group_id',
                'pg.posting_date',
                'pg.source_type',
                'pg.source_id',
                'ar.id as allocation_row_id',
                'ar.allocation_type',
                'ar.amount',
                'ar.party_id',
            ])
            ->orderBy('pg.posting_date')
            ->orderBy('pg.created_at')
            ->orderBy('ar.id')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'posting_group_id' => $row->posting_group_id,
                'posting_date' => $row->posting_date,
                'source_type' => $row->source_type,
                'source_id' => $row->source_id,
                'allocation_row_id' => $row->allocation_row_id,
                'allocation_type' => $row->allocation_type,
                'amount' => (string) $row->amount,
                'party_id' => $row->party_id,
            ];
        }
        return $result;
    }

    private function countRegisterRows(string $projectId, string $tenantId): int
    {
        return (int) DB::table('allocation_rows')
            ->where('tenant_id', $tenantId)
            ->where('project_id', $projectId)
            ->count();
    }

    /**
     * @param list<array{amount: string, allocation_type: string}> $registerRows
     * @return array{total_amount: string, row_count: int, by_allocation_type: array<string, string>}
     */
    private function buildSummary(array $registerRows): array
    {
        $total = '0';
        $byType = [];
        foreach ($registerRows as $row) {
            $amt = $row['amount'] ?? '0';
            $total = (string) (floatval($total) + floatval($amt));
            $type = $row['allocation_type'] ?? 'OTHER';
            $byType[$type] = (string) (floatval($byType[$type] ?? '0') + floatval($amt));
        }
        return [
            'total_amount' => $total,
            'row_count' => count($registerRows),
            'by_allocation_type' => $byType,
        ];
    }
}
