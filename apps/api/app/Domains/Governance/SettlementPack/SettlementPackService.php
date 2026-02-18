<?php

namespace App\Domains\Governance\SettlementPack;

use App\Models\Project;
use App\Models\SettlementPack;
use App\Models\SettlementPackApproval;
use App\Models\AllocationRow;
use App\Models\CropCycle;
use App\Models\User;
use App\Domains\Reporting\TrialBalanceService;
use App\Domains\Reporting\ProfitLossService;
use App\Domains\Reporting\BalanceSheetService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Generates and retrieves Settlement Packs (read-only snapshot of project transaction register).
 * Embeds financial statement snapshots. State: DRAFT → PENDING_APPROVAL → FINAL.
 * Finalization (FINAL + project CLOSED) occurs only when all required approvals are APPROVED.
 * No ledger or posting group mutations.
 */
class SettlementPackService
{
    /** Required roles that must approve before pack can be finalized (v4 hardcoded). */
    private const REQUIRED_APPROVAL_ROLES = ['tenant_admin', 'accountant'];

    public function __construct(
        private TrialBalanceService $trialBalanceService,
        private ProfitLossService $profitLossService,
        private BalanceSheetService $balanceSheetService
    ) {}

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
        $registerSummary = $this->buildSummary($registerRows);

        $project->load('cropCycle');
        $cropCycle = $project->cropCycle;
        $from = $cropCycle && $cropCycle->start_date
            ? $cropCycle->start_date->format('Y-m-d')
            : now()->startOfYear()->format('Y-m-d');
        $asOf = $this->maxPostingDateForProject($projectId, $tenantId) ?? $from;

        $filters = [
            'project_id' => $projectId,
            'crop_cycle_id' => $project->crop_cycle_id,
        ];
        $trialBalance = $this->trialBalanceService->getTrialBalance($tenantId, $asOf, $filters);
        $profitLoss = $this->profitLossService->getProfitLoss($tenantId, $from, $asOf, $filters);
        $balanceSheet = $this->balanceSheetService->getBalanceSheet($tenantId, $asOf, $filters);

        $registerRowsForSnapshot = array_map(function (array $row) {
            $r = $row;
            if (isset($r['posting_date']) && is_object($r['posting_date'])) {
                $r['posting_date'] = $r['posting_date']->format('Y-m-d');
            }
            return $r;
        }, $registerRows);

        $summary = array_merge($registerSummary, [
            'register_rows' => $registerRowsForSnapshot,
            'financial_statements' => [
                'trial_balance' => $trialBalance,
                'profit_loss' => $profitLoss,
                'balance_sheet' => $balanceSheet,
            ],
        ]);

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
     * Finalize a settlement pack: only allowed when status is PENDING_APPROVAL and all required
     * approvals are APPROVED. Transition is normally done inside approve(); this method is for
     * explicit finalize when all approvals are already in.
     *
     * @throws ValidationException if pack is not PENDING_APPROVAL or not all required approved
     * @return array{pack: SettlementPack}
     */
    public function finalize(string $packId, string $tenantId, ?string $userId): array
    {
        $pack = SettlementPack::where('id', $packId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        if ($pack->status === SettlementPack::STATUS_DRAFT) {
            throw ValidationException::withMessages([
                'status' => ['Use submit-for-approval first, then obtain all required approvals.'],
            ]);
        }

        if ($pack->status === SettlementPack::STATUS_PENDING_APPROVAL) {
            if (!$this->allRequiredApprovalsGranted($packId, $tenantId)) {
                throw ValidationException::withMessages([
                    'status' => ['All required approvers must approve before finalization.'],
                ]);
            }
            $this->transitionPackToFinal($pack);
            return ['pack' => $pack->fresh()];
        }

        if ($pack->status === SettlementPack::STATUS_FINAL) {
            return ['pack' => $pack->fresh()];
        }

        throw ValidationException::withMessages([
            'status' => ['Pack cannot be finalized in current status.'],
        ]);
    }

    /**
     * Submit pack for approval: DRAFT → PENDING_APPROVAL. Creates approval rows for required roles.
     *
     * @return array{pack: SettlementPack, approvals: array}
     */
    public function submitForApproval(string $packId, string $tenantId, ?string $userId): array
    {
        $pack = SettlementPack::where('id', $packId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        if ($pack->status !== SettlementPack::STATUS_DRAFT) {
            throw ValidationException::withMessages([
                'status' => ['Pack must be in DRAFT status to submit for approval.'],
            ]);
        }

        $summary = $pack->summary_json ?? [];
        if (empty($summary)) {
            throw ValidationException::withMessages([
                'summary' => ['Pack must have a snapshot before submitting for approval.'],
            ]);
        }

        $snapshotSha256 = $this->snapshotHash($summary);

        $approvals = [];
        foreach (self::REQUIRED_APPROVAL_ROLES as $role) {
            $user = User::where('tenant_id', $tenantId)
                ->where('role', $role)
                ->where('is_enabled', true)
                ->first();
            if ($user) {
                SettlementPackApproval::create([
                    'tenant_id' => $tenantId,
                    'settlement_pack_id' => $packId,
                    'approver_user_id' => $user->id,
                    'approver_role' => $role,
                    'status' => SettlementPackApproval::STATUS_PENDING,
                    'snapshot_sha256' => $snapshotSha256,
                ]);
                $approvals[] = [
                    'approver_user_id' => $user->id,
                    'approver_role' => $role,
                    'status' => SettlementPackApproval::STATUS_PENDING,
                ];
            }
        }

        if (empty($approvals)) {
            throw ValidationException::withMessages([
                'approvals' => ['No users with required approval roles (tenant_admin, accountant) found for this tenant.'],
            ]);
        }

        $pack->update(['status' => SettlementPack::STATUS_PENDING_APPROVAL]);

        return [
            'pack' => $pack->fresh(),
            'approvals' => $this->formatApprovalsForResponse($pack->id, $tenantId),
        ];
    }

    /**
     * Record approval by the given user. If all required approvals are APPROVED, transition pack to FINAL.
     *
     * @return array{pack: SettlementPack, approvals: array}
     */
    public function approve(string $packId, string $tenantId, string $userId, ?string $comment = null): array
    {
        $pack = SettlementPack::where('id', $packId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        if ($pack->status !== SettlementPack::STATUS_PENDING_APPROVAL) {
            throw ValidationException::withMessages([
                'status' => ['Pack must be in PENDING_APPROVAL status to record approval.'],
            ]);
        }

        $approval = SettlementPackApproval::where('tenant_id', $tenantId)
            ->where('settlement_pack_id', $packId)
            ->where('approver_user_id', $userId)
            ->firstOrFail();

        if ($approval->status !== SettlementPackApproval::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'approval' => ['You have already recorded a decision for this pack.'],
            ]);
        }

        $currentSnapshotHash = $this->snapshotHash($pack->summary_json ?? []);
        if ($currentSnapshotHash !== $approval->snapshot_sha256) {
            throw ValidationException::withMessages([
                'snapshot' => ['Pack snapshot has changed since submission; approval rejected for integrity.'],
            ]);
        }

        $approval->update([
            'status' => SettlementPackApproval::STATUS_APPROVED,
            'approved_at' => now(),
            'comment' => $comment,
        ]);

        if ($this->allRequiredApprovalsGranted($packId, $tenantId)) {
            $this->transitionPackToFinal($pack);
        }

        return [
            'pack' => $pack->fresh(),
            'approvals' => $this->formatApprovalsForResponse($pack->id, $tenantId),
        ];
    }

    /**
     * Record rejection. Pack remains PENDING_APPROVAL.
     *
     * @return array{pack: SettlementPack, approvals: array}
     */
    public function reject(string $packId, string $tenantId, string $userId, ?string $comment = null): array
    {
        $pack = SettlementPack::where('id', $packId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        if ($pack->status !== SettlementPack::STATUS_PENDING_APPROVAL) {
            throw ValidationException::withMessages([
                'status' => ['Pack must be in PENDING_APPROVAL status to record rejection.'],
            ]);
        }

        $approval = SettlementPackApproval::where('tenant_id', $tenantId)
            ->where('settlement_pack_id', $packId)
            ->where('approver_user_id', $userId)
            ->firstOrFail();

        if ($approval->status !== SettlementPackApproval::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'approval' => ['You have already recorded a decision for this pack.'],
            ]);
        }

        $approval->update([
            'status' => SettlementPackApproval::STATUS_REJECTED,
            'rejected_at' => now(),
            'comment' => $comment,
        ]);

        return [
            'pack' => $pack->fresh(),
            'approvals' => $this->formatApprovalsForResponse($pack->id, $tenantId),
        ];
    }

    private function allRequiredApprovalsGranted(string $packId, string $tenantId): bool
    {
        $count = SettlementPackApproval::where('tenant_id', $tenantId)
            ->where('settlement_pack_id', $packId)
            ->where('status', SettlementPackApproval::STATUS_APPROVED)
            ->count();
        $required = SettlementPackApproval::where('tenant_id', $tenantId)
            ->where('settlement_pack_id', $packId)
            ->count();
        return $required > 0 && $count === $required;
    }

    private function transitionPackToFinal(SettlementPack $pack): void
    {
        $pack->update([
            'status' => SettlementPack::STATUS_FINAL,
            'finalized_at' => now(),
            'finalized_by_user_id' => null,
        ]);
        $pack->project()->update(['status' => 'CLOSED']);
    }

    /**
     * @return array<int, array{approver_user_id: string, approver_role: string, status: string, approved_at: ?string, rejected_at: ?string}>
     */
    private function formatApprovalsForResponse(string $packId, string $tenantId): array
    {
        $rows = SettlementPackApproval::where('tenant_id', $tenantId)
            ->where('settlement_pack_id', $packId)
            ->orderBy('approver_role')
            ->get();

        return $rows->map(fn (SettlementPackApproval $a) => [
            'approver_user_id' => $a->approver_user_id,
            'approver_role' => $a->approver_role,
            'status' => $a->status,
            'approved_at' => $a->approved_at?->toIso8601String(),
            'rejected_at' => $a->rejected_at?->toIso8601String(),
        ])->values()->all();
    }

    private function snapshotHash(array $summaryJson): string
    {
        $canonical = json_encode($summaryJson, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        return strtolower(bin2hex(hash('sha256', $canonical, true)));
    }

    /**
     * Max posting_date for the project (from allocation_rows + posting_groups). Returns Y-m-d or null if none.
     */
    private function maxPostingDateForProject(string $projectId, string $tenantId): ?string
    {
        $row = DB::table('allocation_rows as ar')
            ->join('posting_groups as pg', 'pg.id', '=', 'ar.posting_group_id')
            ->where('ar.tenant_id', $tenantId)
            ->where('ar.project_id', $projectId)
            ->selectRaw('MAX(pg.posting_date) as max_date')
            ->first();

        if (!$row || !$row->max_date) {
            return null;
        }
        $date = $row->max_date;
        return is_object($date) && method_exists($date, 'format')
            ? $date->format('Y-m-d')
            : (string) $date;
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
