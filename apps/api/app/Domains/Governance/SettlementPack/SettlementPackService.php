<?php

namespace App\Domains\Governance\SettlementPack;

use App\Domains\Reporting\BalanceSheetService;
use App\Domains\Reporting\ProfitLossService;
use App\Domains\Reporting\TrialBalanceService;
use App\Models\Project;
use App\Models\SettlementPack;
use App\Models\SettlementPackApproval;
use App\Models\SettlementPackVersion;
use App\Models\User;
use App\Support\TenantScoped;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Generates and retrieves Settlement Packs (read-only snapshot of project transaction register).
 * Embeds financial statement snapshots. State: DRAFT → FINALIZED (via approvals). No ledger or posting group mutations.
 */
class SettlementPackService
{
    /** Required roles that must approve before pack can be finalized (v4 hardcoded). */
    private const REQUIRED_APPROVAL_ROLES = ['tenant_admin', 'accountant'];

    public function __construct(
        private TrialBalanceService $trialBalanceService,
        private ProfitLossService $profitLossService,
        private BalanceSheetService $balanceSheetService,
        private SettlementPackBuilder $settlementPackBuilder,
        private SettlementPackRegisterQuery $settlementPackRegisterQuery,
        private FinalizeSettlementPackAction $finalizeSettlementPackAction
    ) {}

    /**
     * Generate or return existing settlement pack for project + reference_no (idempotent).
     *
     * @return array{pack: SettlementPack, summary: array, register_row_count: int}
     */
    public function generateOrReturn(
        string $projectId,
        string $tenantId,
        ?string $preparedByUserId,
        string $referenceNo = 'default'
    ): array {
        $project = Project::where('id', $projectId)->where('tenant_id', $tenantId)->firstOrFail();

        $existing = TenantScoped::for(SettlementPack::query(), $tenantId)
            ->where('project_id', $projectId)
            ->where('reference_no', $referenceNo)
            ->first();

        if ($existing) {
            $summary = $existing->snapshotJson();
            $registerRowCount = (int) ($summary['row_count'] ?? count($summary['register_rows'] ?? []));

            return [
                'pack' => $existing,
                'summary' => $summary,
                'register_row_count' => $registerRowCount,
            ];
        }

        if (! $project->crop_cycle_id) {
            throw ValidationException::withMessages([
                'project' => ['Project must have a crop cycle before creating a settlement pack.'],
            ]);
        }

        $project->load('cropCycle');
        $cropCycle = $project->cropCycle;
        $from = $cropCycle && $cropCycle->start_date
            ? $cropCycle->start_date->format('Y-m-d')
            : now()->startOfYear()->format('Y-m-d');
        $asOf = $this->maxPostingDateForProject($projectId, $tenantId) ?? $from;

        $registerSnapshot = $this->settlementPackBuilder->build($tenantId, $projectId, $asOf);

        $filters = [
            'project_id' => $projectId,
            'crop_cycle_id' => $project->crop_cycle_id,
        ];
        $trialBalance = $this->trialBalanceService->getTrialBalance($tenantId, $asOf, $filters);
        $profitLoss = $this->profitLossService->getProfitLoss($tenantId, $from, $asOf, $filters);
        $balanceSheet = $this->balanceSheetService->getBalanceSheet($tenantId, $asOf, $filters);

        $summary = array_merge($registerSnapshot, [
            'financial_statements' => [
                'trial_balance' => $trialBalance,
                'profit_loss' => $profitLoss,
                'balance_sheet' => $balanceSheet,
            ],
        ]);

        $pack = DB::transaction(function () use (
            $tenantId,
            $projectId,
            $project,
            $preparedByUserId,
            $referenceNo,
            $asOf,
            $summary
        ) {
            $pack = SettlementPack::create([
                'tenant_id' => $tenantId,
                'project_id' => $projectId,
                'crop_cycle_id' => $project->crop_cycle_id,
                'prepared_by_user_id' => $preparedByUserId,
                'prepared_at' => now(),
                'status' => SettlementPack::STATUS_DRAFT,
                'reference_no' => $referenceNo,
                'as_of_date' => $asOf,
                'notes' => null,
            ]);

            SettlementPackVersion::create([
                'tenant_id' => $tenantId,
                'settlement_pack_id' => $pack->id,
                'version_no' => 1,
                'snapshot_json' => $summary,
                'generated_by_user_id' => $preparedByUserId,
                'generated_at' => now(),
                'pdf_path' => null,
            ]);

            return $pack;
        });

        return [
            'pack' => $pack,
            'summary' => $summary,
            'register_row_count' => (int) ($registerSnapshot['row_count'] ?? 0),
        ];
    }

    /**
     * Finalize a settlement pack (DRAFT → FINALIZED). Requires a snapshot version; does not mutate accounting data or close the project.
     *
     * @return array{pack: SettlementPack}
     *
     * @throws ValidationException if pack cannot be finalized
     */
    public function finalize(string $packId, string $tenantId, ?string $userId): array
    {
        $pack = TenantScoped::for(SettlementPack::query(), $tenantId)->findOrFail($packId);

        $pack = $this->finalizeSettlementPackAction->execute($pack, $userId);

        return ['pack' => $pack];
    }

    /**
     * List settlement packs for the tenant (most recently updated first).
     *
     * @return list<array<string, mixed>>
     */
    public function listForTenant(string $tenantId, ?string $statusFilter = null): array
    {
        $q = TenantScoped::for(SettlementPack::query(), $tenantId)
            ->with(['project:id,name'])
            ->orderByDesc('updated_at')
            ->limit(200);
        if ($statusFilter !== null && $statusFilter !== '') {
            $q->where('status', $statusFilter);
        }

        return $q->get()->map(function (SettlementPack $p) {
            return [
                'id' => $p->id,
                'project_id' => $p->project_id,
                'reference_no' => $p->reference_no,
                'status' => $p->status,
                'as_of_date' => $p->as_of_date?->format('Y-m-d'),
                'prepared_at' => $p->prepared_at?->toIso8601String(),
                'finalized_at' => $p->finalized_at?->toIso8601String(),
                'is_read_only' => $p->isReadOnly(),
                'project' => $p->project ? [
                    'id' => $p->project->id,
                    'name' => $p->project->name,
                ] : null,
            ];
        })->values()->all();
    }

    /**
     * Register payload for API: rows, detailed lines, and snapshot metrics (from persisted snapshot when available).
     *
     * @return array{register_rows: array, register_lines: array, metrics: mixed, content_hash: ?string, as_of_date: string}
     */
    public function getRegisterPayload(string $packId, string $tenantId): array
    {
        $pack = TenantScoped::for(SettlementPack::query(), $tenantId)->findOrFail($packId);
        $summary = $pack->snapshotJson();
        $asOf = $pack->as_of_date?->format('Y-m-d') ?? now()->format('Y-m-d');

        $registerRows = $summary['register_rows'] ?? [];
        if ($registerRows === []) {
            $registerRows = $this->settlementPackRegisterQuery->allocationRegisterRows($tenantId, $pack->project_id, $asOf);
        }

        $registerLines = $summary['register_lines'] ?? [];
        if ($registerLines === [] && ! $pack->isReadOnly()) {
            $registerLines = $this->settlementPackRegisterQuery->registerLines($tenantId, $pack->project_id, $asOf);
        }

        return [
            'register_rows' => $registerRows,
            'register_lines' => $registerLines,
            'metrics' => $summary['metrics'] ?? null,
            'content_hash' => $summary['content_hash'] ?? null,
            'as_of_date' => $asOf,
        ];
    }

    /**
     * Append a new snapshot version (rebuilds register + financial statements). Does not post to the ledger.
     *
     * @return array{pack: SettlementPack, summary: array, version_no: int}
     */
    public function generateNextSnapshotVersion(string $packId, string $tenantId, ?string $userId): array
    {
        $pack = TenantScoped::for(SettlementPack::query(), $tenantId)
            ->with('project.cropCycle')
            ->findOrFail($packId);

        if ($pack->isReadOnly()) {
            throw ValidationException::withMessages([
                'status' => ['Cannot generate a new snapshot version while the pack is finalized.'],
            ]);
        }

        $project = $pack->project;
        if (! $project || ! $project->crop_cycle_id) {
            throw ValidationException::withMessages([
                'project' => ['Project must have a crop cycle.'],
            ]);
        }

        $asOf = $pack->as_of_date?->format('Y-m-d')
            ?? $this->maxPostingDateForProject($pack->project_id, $tenantId)
            ?? now()->format('Y-m-d');

        $cropCycle = $project->cropCycle;
        $from = $cropCycle && $cropCycle->start_date
            ? $cropCycle->start_date->format('Y-m-d')
            : now()->startOfYear()->format('Y-m-d');

        $registerSnapshot = $this->settlementPackBuilder->build($tenantId, $pack->project_id, $asOf);

        $filters = [
            'project_id' => $pack->project_id,
            'crop_cycle_id' => $project->crop_cycle_id,
        ];
        $trialBalance = $this->trialBalanceService->getTrialBalance($tenantId, $asOf, $filters);
        $profitLoss = $this->profitLossService->getProfitLoss($tenantId, $from, $asOf, $filters);
        $balanceSheet = $this->balanceSheetService->getBalanceSheet($tenantId, $asOf, $filters);

        $summary = array_merge($registerSnapshot, [
            'financial_statements' => [
                'trial_balance' => $trialBalance,
                'profit_loss' => $profitLoss,
                'balance_sheet' => $balanceSheet,
            ],
        ]);

        $nextVersion = (int) (TenantScoped::for(SettlementPackVersion::query(), $tenantId)
            ->where('settlement_pack_id', $packId)
            ->max('version_no')) + 1;

        DB::transaction(function () use ($tenantId, $packId, $userId, $summary, $nextVersion) {
            SettlementPackVersion::create([
                'tenant_id' => $tenantId,
                'settlement_pack_id' => $packId,
                'version_no' => $nextVersion,
                'snapshot_json' => $summary,
                'generated_by_user_id' => $userId,
                'generated_at' => now(),
                'pdf_path' => null,
            ]);
        });

        return [
            'pack' => $pack->fresh(),
            'summary' => $summary,
            'version_no' => $nextVersion,
        ];
    }

    /**
     * Submit pack for approval: creates approval rows for required roles. Pack remains DRAFT until finalized via approvals.
     *
     * @return array{pack: SettlementPack, approvals: array}
     */
    public function submitForApproval(string $packId, string $tenantId, ?string $userId): array
    {
        $pack = TenantScoped::for(SettlementPack::query(), $tenantId)->findOrFail($packId);

        if ($pack->status !== SettlementPack::STATUS_DRAFT) {
            throw ValidationException::withMessages([
                'status' => ['Pack must be in DRAFT status to submit for approval.'],
            ]);
        }

        $summary = $pack->snapshotJson();
        if (empty($summary['register_rows'] ?? null) && empty($summary['register_lines'] ?? null)) {
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

        return [
            'pack' => $pack->fresh(),
            'approvals' => $this->formatApprovalsForResponse($pack->id, $tenantId),
        ];
    }

    /**
     * Record approval by the given user. If all required approvals are APPROVED, transition pack to FINALIZED.
     *
     * @return array{pack: SettlementPack, approvals: array}
     */
    public function approve(string $packId, string $tenantId, string $userId, ?string $comment = null): array
    {
        $pack = TenantScoped::for(SettlementPack::query(), $tenantId)->findOrFail($packId);

        if ($pack->status !== SettlementPack::STATUS_DRAFT) {
            throw ValidationException::withMessages([
                'status' => ['Pack must be in DRAFT status to record approval.'],
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

        $currentSnapshotHash = $this->snapshotHash($pack->snapshotJson());
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
            $this->finalizeSettlementPackAction->execute($pack->fresh(), $userId);
        }

        return [
            'pack' => $pack->fresh(),
            'approvals' => $this->formatApprovalsForResponse($pack->id, $tenantId),
        ];
    }

    /**
     * Record rejection. Pack remains DRAFT.
     *
     * @return array{pack: SettlementPack, approvals: array}
     */
    public function reject(string $packId, string $tenantId, string $userId, ?string $comment = null): array
    {
        $pack = TenantScoped::for(SettlementPack::query(), $tenantId)->findOrFail($packId);

        if ($pack->status !== SettlementPack::STATUS_DRAFT) {
            throw ValidationException::withMessages([
                'status' => ['Pack must be in DRAFT status to record rejection.'],
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

        if (! $row || ! $row->max_date) {
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
        $pack = TenantScoped::for(SettlementPack::query(), $tenantId)
            ->with(['project', 'preparedByUser'])
            ->findOrFail($packId);

        $summary = $pack->snapshotJson();
        $asOf = $pack->as_of_date?->format('Y-m-d') ?? $this->maxPostingDateForProject($pack->project_id, $tenantId) ?? now()->format('Y-m-d');

        if (! empty($summary['register_rows'])) {
            $registerRows = $summary['register_rows'];
        } else {
            $registerRows = $this->settlementPackRegisterQuery->allocationRegisterRows($tenantId, $pack->project_id, $asOf);
        }

        if ($summary === [] && $registerRows !== []) {
            $summary = $this->buildSummaryFromAllocationRows($registerRows);
        }

        return [
            'pack' => $pack,
            'summary' => $summary,
            'register_rows' => $registerRows,
        ];
    }

    /**
     * Build transaction register rows for the project (allocation rows + posting group info).
     * Posted, non-reversed posting groups only; respects as-of when used from {@see SettlementPackRegisterQuery}.
     *
     * @return list<array{posting_group_id: string, posting_date: string, source_type: string, source_id: string, allocation_row_id: string, allocation_type: string, amount: string, party_id: string|null}>
     */
    public function buildRegisterRows(string $projectId, string $tenantId): array
    {
        $asOf = $this->maxPostingDateForProject($projectId, $tenantId) ?? now()->format('Y-m-d');
        $rows = $this->settlementPackRegisterQuery->allocationRegisterRows($tenantId, $projectId, $asOf);

        return array_map(function (array $row) {
            return [
                'posting_group_id' => $row['posting_group_id'],
                'posting_date' => $row['posting_date'],
                'source_type' => $row['source_type'],
                'source_id' => $row['source_id'],
                'allocation_row_id' => $row['allocation_row_id'],
                'allocation_type' => $row['allocation_type'],
                'amount' => $row['amount'],
                'party_id' => $row['party_id'],
            ];
        }, $rows);
    }

    /**
     * @param  list<array{amount: string, allocation_type: string}>  $registerRows
     * @return array{total_amount: string, row_count: int, by_allocation_type: array<string, string>}
     */
    private function buildSummaryFromAllocationRows(array $registerRows): array
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
