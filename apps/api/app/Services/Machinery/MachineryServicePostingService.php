<?php

namespace App\Services\Machinery;

use App\Models\MachineryService;
use App\Models\AllocationRow;
use App\Models\LedgerEntry;
use App\Models\PostingGroup;
use App\Models\CropCycle;
use App\Models\InvIssue;
use App\Models\InvIssueLine;
use App\Models\ProjectRule;
use App\Services\OperationalPostingGuard;
use App\Services\ReversalService;
use App\Services\SystemAccountService;
use App\Services\InventoryPostingService;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class MachineryServicePostingService
{
    private const SOURCE_TYPE = 'MACHINERY_SERVICE';

    public function __construct(
        private SystemAccountService $accountService,
        private ReversalService $reversalService,
        private OperationalPostingGuard $guard,
        private InventoryPostingService $inventoryPostingService
    ) {}

    /**
     * Post an internal machinery service. Idempotent via idempotency_key or (source_type, source_id).
     * Resolves amount from rate card × quantity at POST time. Creates PostingGroup with one
     * AllocationRow (MACHINERY_SERVICE) and balanced LedgerEntries (Dr Expense, Cr Clearing).
     *
     * @throws \Exception
     */
    public function postService(string $serviceId, string $tenantId, string $postingDate, ?string $idempotencyKey = null): PostingGroup
    {
        $key = $idempotencyKey ?? 'machinery_service:' . $serviceId . ':post';

        return DB::transaction(function () use ($serviceId, $tenantId, $postingDate, $key) {
            $existing = PostingGroup::where('tenant_id', $tenantId)->where('idempotency_key', $key)->first();
            if ($existing) {
                return $existing->load(['allocationRows', 'ledgerEntries.account']);
            }

            $existingBySource = PostingGroup::where('tenant_id', $tenantId)
                ->where('source_type', self::SOURCE_TYPE)
                ->where('source_id', $serviceId)
                ->first();
            if ($existingBySource) {
                return $existingBySource->load(['allocationRows', 'ledgerEntries.account']);
            }

            $service = MachineryService::where('id', $serviceId)
                ->where('tenant_id', $tenantId)
                ->where('status', MachineryService::STATUS_DRAFT)
                ->with(['machine', 'project.cropCycle', 'rateCard'])
                ->firstOrFail();

            if (!$service->project->crop_cycle_id) {
                throw new \Exception('Project has no crop cycle.');
            }

            $this->guard->ensureCropCycleOpenForProject($service->project_id, $tenantId);

            $postingDateObj = Carbon::parse($postingDate)->format('Y-m-d');
            $cropCycle = CropCycle::where('id', $service->project->crop_cycle_id)->where('tenant_id', $tenantId)->firstOrFail();
            if ($cropCycle->start_date && $postingDateObj < $cropCycle->start_date->format('Y-m-d')) {
                throw new \Exception('Posting date is before crop cycle start date.');
            }
            if ($cropCycle->end_date && $postingDateObj > $cropCycle->end_date->format('Y-m-d')) {
                throw new \Exception('Posting date is after crop cycle end date.');
            }

            $rateCard = $service->rateCard;
            if (!$rateCard || $rateCard->base_rate === null) {
                throw new \Exception('Rate card has no base rate.');
            }
            $amount = (float) $rateCard->base_rate * (float) $service->quantity;

            $expenseAccount = $this->accountService->getByCode($tenantId, 'MACHINERY_SERVICE_EXPENSE');
            $clearingAccount = $this->accountService->getByCode($tenantId, 'MACHINERY_INTERNAL_SERVICE_CLEARING');

            $postingGroup = PostingGroup::create([
                'tenant_id' => $tenantId,
                'crop_cycle_id' => $service->project->crop_cycle_id,
                'source_type' => self::SOURCE_TYPE,
                'source_id' => $service->id,
                'posting_date' => $postingDateObj,
                'idempotency_key' => $key,
            ]);

            AllocationRow::create([
                'tenant_id' => $tenantId,
                'posting_group_id' => $postingGroup->id,
                'project_id' => $service->project_id,
                'party_id' => $service->project->party_id,
                'allocation_type' => 'MACHINERY_SERVICE',
                'allocation_scope' => $service->allocation_scope,
                'amount' => (string) $amount,
                'quantity' => $service->quantity,
                'unit' => $rateCard->rate_unit ?? null,
                'machine_id' => $service->machine_id,
                'rule_snapshot' => [
                    'source' => 'machinery_service',
                    'machinery_service_id' => $service->id,
                    'rate_card_id' => $service->rate_card_id,
                    'base_rate' => (float) $rateCard->base_rate,
                    'quantity' => (float) $service->quantity,
                    'posting_date' => $postingDateObj,
                ],
            ]);

            LedgerEntry::create([
                'tenant_id' => $tenantId,
                'posting_group_id' => $postingGroup->id,
                'account_id' => $expenseAccount->id,
                'debit_amount' => (string) $amount,
                'credit_amount' => '0.00',
                'currency_code' => 'GBP',
            ]);
            LedgerEntry::create([
                'tenant_id' => $tenantId,
                'posting_group_id' => $postingGroup->id,
                'account_id' => $clearingAccount->id,
                'debit_amount' => '0.00',
                'credit_amount' => (string) $amount,
                'currency_code' => 'GBP',
            ]);

            $inKindIssueId = null;
            $resolvedInKindQty = null;

            if ($service->in_kind_item_id !== null && $service->in_kind_rate_per_unit !== null) {
                if (!$service->in_kind_store_id) {
                    throw new \Exception('In-kind payment requires in_kind_store_id when in_kind_item_id is set.');
                }
                $resolvedInKindQty = (float) $service->quantity * (float) $service->in_kind_rate_per_unit;

                $project = $service->project;
                $projectRule = ProjectRule::where('project_id', $project->id)->first();
                if (!$projectRule) {
                    throw new \Exception('Project rules not found for in-kind allocation.');
                }

                $issuePayload = [
                    'tenant_id' => $tenantId,
                    'doc_no' => 'MS-INKIND-' . $service->id,
                    'store_id' => $service->in_kind_store_id,
                    'crop_cycle_id' => $project->crop_cycle_id,
                    'project_id' => $project->id,
                    'doc_date' => $postingDateObj,
                    'status' => 'DRAFT',
                    'allocation_mode' => $service->allocation_scope,
                ];
                if ($service->allocation_scope === MachineryService::ALLOCATION_SCOPE_HARI_ONLY) {
                    $issuePayload['hari_id'] = $project->party_id;
                } else {
                    $issuePayload['landlord_share_pct'] = $projectRule->profit_split_landlord_pct;
                    $issuePayload['hari_share_pct'] = $projectRule->profit_split_hari_pct;
                }
                $issue = InvIssue::create($issuePayload);
                InvIssueLine::create([
                    'tenant_id' => $tenantId,
                    'issue_id' => $issue->id,
                    'item_id' => $service->in_kind_item_id,
                    'qty' => (string) $resolvedInKindQty,
                ]);

                $this->inventoryPostingService->postIssue(
                    $issue->id,
                    $tenantId,
                    $postingDateObj,
                    'machinery_service_in_kind:' . $serviceId
                );
                $inKindIssueId = $issue->id;
            }

            $service->update([
                'status' => MachineryService::STATUS_POSTED,
                'amount' => (string) $amount,
                'posting_date' => $postingDateObj,
                'posted_at' => now(),
                'posting_group_id' => $postingGroup->id,
                'in_kind_quantity' => $resolvedInKindQty !== null ? (string) $resolvedInKindQty : null,
                'in_kind_inventory_issue_id' => $inKindIssueId,
            ]);

            return $postingGroup->fresh(['allocationRows', 'ledgerEntries.account']);
        });
    }

    /**
     * Reverse a posted machinery service via ReversalService.
     * Relies exclusively on ReversalService for reversal PostingGroup, AllocationRows and LedgerEntries.
     * No manual negation of allocation row amounts (avoids double-negation; ledger nets via swapped debits/credits).
     *
     * @throws \Exception
     */
    public function reverseService(string $serviceId, string $tenantId, string $postingDate, ?string $reason = null): PostingGroup
    {
        $reason = $reason ?? 'Reversed';

        return DB::transaction(function () use ($serviceId, $tenantId, $postingDate, $reason) {
            $service = MachineryService::where('id', $serviceId)
                ->where('tenant_id', $tenantId)
                ->with(['postingGroup'])
                ->firstOrFail();

            if (!$service->isPosted()) {
                throw new \Exception('Only posted machinery services can be reversed.');
            }
            if ($service->isReversed()) {
                throw new \Exception('Machinery service is already reversed.');
            }

            $originalPostingGroup = $service->postingGroup;
            if (!$originalPostingGroup) {
                throw new \Exception('Service has no posting group to reverse.');
            }

            $this->guard->ensureCropCycleOpen($originalPostingGroup->crop_cycle_id, $tenantId);

            $reversalPostingGroup = $this->reversalService->reversePostingGroup(
                $originalPostingGroup->id,
                $tenantId,
                $postingDate,
                $reason
            );

            if ($service->in_kind_inventory_issue_id) {
                $this->inventoryPostingService->reverseIssue(
                    $service->in_kind_inventory_issue_id,
                    $tenantId,
                    $postingDate,
                    $reason . ' (machinery service reversal)'
                );
            }

            $service->update([
                'status' => MachineryService::STATUS_REVERSED,
                'reversal_posting_group_id' => $reversalPostingGroup->id,
            ]);

            return $reversalPostingGroup->fresh(['allocationRows', 'ledgerEntries.account']);
        });
    }
}
